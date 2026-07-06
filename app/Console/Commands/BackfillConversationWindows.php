<?php

namespace App\Console\Commands;

use App\Models\ConversationWindow;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillConversationWindows extends Command
{
    protected $signature = 'subscription:backfill-conversation-windows
        {--organization= : Limit backfill to a single organization ID}
        {--dry-run : Show how many conversation windows would be created without writing}
        {--without-archive : Ignore chats_archive even when the table exists}
        {--report-file= : Write the full per-organization report to a CSV file}
        {--report-limit=100 : Number of organizations to print in the console report}
        {--progress-every=50000 : Print progress after this many chat rows are scanned}
        {--insert-batch=1000 : Number of conversation windows to insert per batch}';

    protected $description = 'Backfill 24-hour conversation windows from existing chat records.';

    private int $created = 0;
    private int $existing = 0;
    private int $insideWindow = 0;
    private int $skipped = 0;
    private array $organizationStats = [];
    private ?int $currentOrganizationId = null;
    private ?int $currentContactId = null;
    private ?Carbon $currentClosesAt = null;
    private array $existingWindows = [];
    private int $existingWindowIndex = 0;
    private bool $hasExistingWindows = false;
    private bool $checkExistingWindows = false;
    private array $pendingWindowRows = [];
    private int $insertBatchSize = 1000;
    private int $scanned = 0;
    private int $progressEvery = 50000;

    public function handle(): int
    {
        if (! Schema::hasTable('conversation_windows')) {
            $this->error('conversation_windows table does not exist. Run migrations first.');
            return self::FAILURE;
        }

        if (! Schema::hasTable('chats')) {
            $this->error('chats table does not exist.');
            return self::FAILURE;
        }

        if (! Schema::hasTable('contacts')) {
            $this->error('contacts table does not exist.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $includeArchive = ! (bool) $this->option('without-archive') && Schema::hasTable('chats_archive');
        $this->insertBatchSize = max(1, (int) $this->option('insert-batch'));
        $this->progressEvery = max(0, (int) $this->option('progress-every'));
        $organizationId = $this->option('organization') !== null
            ? (int) $this->option('organization')
            : null;

        DB::disableQueryLog();

        $sourceTables = $this->sourceTables($includeArchive);

        $this->info($dryRun
            ? 'Dry run: no conversation windows will be written.'
            : 'Backfilling conversation windows...');
        $this->line('Sources: '.implode(', ', array_values($sourceTables)));

        if ($dryRun && $includeArchive) {
            $this->warn('Dry-run with chats_archive is a scalable estimate. The real run may create fewer windows because archive windows are written before active chats are processed.');
        }

        $startedWithExistingWindows = ConversationWindow::query()
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->exists();
        $this->hasExistingWindows = $startedWithExistingWindows;

        foreach ($sourceTables as $sourceTable => $table) {
            $this->line("Processing {$table}...");
            $checkExistingWindows = $startedWithExistingWindows
                || (! $dryRun && $includeArchive && $sourceTable === 'chats' && $this->hasExistingWindows);
            $this->processSourceTable($table, $sourceTable, $organizationId, $dryRun, $checkExistingWindows);
        }

        $this->flushPendingWindowRows();

        $this->newLine();
        $this->printOrganizationStats();
        $this->newLine();
        $this->info("Created: {$this->created}");
        $this->info("Already existing: {$this->existing}");
        $this->info("Inside existing/new 24h window: {$this->insideWindow}");
        $this->info("Skipped: {$this->skipped}");
        $this->info("Scanned chat rows: {$this->scanned}");

        return self::SUCCESS;
    }

    private function processChat(object $chat, bool $dryRun): void
    {
        if (! $chat->organization_id || ! $chat->contact_id || ! $chat->created_at) {
            $this->skipped++;
            return;
        }

        $organizationId = (int) $chat->organization_id;
        $contactId = (int) $chat->contact_id;
        $openedAt = Carbon::parse($chat->created_at);

        if ($this->currentOrganizationId !== $organizationId || $this->currentContactId !== $contactId) {
            $this->loadExistingWindows($organizationId, $contactId);
        }

        if ($this->currentClosesAt && $openedAt->lt($this->currentClosesAt)) {
            $this->incrementOrganizationStat($organizationId, 'inside_window');
            $this->insideWindow++;
            return;
        }

        $existingWindow = $this->existingWindowFor($openedAt);
        if ($existingWindow) {
            $this->currentClosesAt = Carbon::parse($existingWindow->closes_at);
            $this->incrementOrganizationStat($organizationId, 'existing');
            $this->existing++;
            return;
        }

        $closesAt = $openedAt->copy()->addHours(24);

        if (! $dryRun) {
            $now = now();
            $this->pendingWindowRows[] = [
                'organization_id' => $organizationId,
                'contact_id' => $contactId,
                'opened_at' => $openedAt,
                'closes_at' => $closesAt,
                'source_chat_id' => $chat->source_table === 'chats' ? (int) $chat->id : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($this->pendingWindowRows) >= $this->insertBatchSize) {
                $this->flushPendingWindowRows();
            }
        }

        $this->currentClosesAt = $closesAt;
        $this->incrementOrganizationStat($organizationId, 'created');
        $this->created++;
    }

    private function sourceTables(bool $includeArchive): array
    {
        $tables = [];

        if ($includeArchive) {
            $tables['chats_archive'] = 'chats_archive';
        }

        $tables['chats'] = 'chats';

        return $tables;
    }

    private function processSourceTable(string $table, string $sourceTable, ?int $organizationId, bool $dryRun, bool $checkExistingWindows): void
    {
        $this->checkExistingWindows = $checkExistingWindows;

        $query = $this->chatTableQuery($table, $sourceTable, $organizationId)
            ->orderBy("{$table}.organization_id")
            ->orderBy("{$table}.contact_id")
            ->orderBy("{$table}.created_at")
            ->orderBy("{$table}.id");

        foreach ($query->cursor() as $chat) {
            $this->scanned++;
            $this->processChat($chat, $dryRun);

            if ($this->progressEvery > 0 && $this->scanned % $this->progressEvery === 0) {
                $this->line("Scanned {$this->scanned} rows; created {$this->created}, existing {$this->existing}, inside windows {$this->insideWindow}.");
            }
        }

        $this->flushPendingWindowRows();
        $this->resetCurrentContactState();
    }

    private function chatTableQuery(string $table, string $sourceTable, ?int $organizationId)
    {
        $query = DB::table($table)
            ->join('contacts', function ($join) use ($table): void {
                $join->on('contacts.id', '=', "{$table}.contact_id")
                    ->on('contacts.organization_id', '=', "{$table}.organization_id");
            })
            ->select([
                "{$table}.id",
                "{$table}.organization_id",
                "{$table}.contact_id",
                "{$table}.created_at",
            ])
            ->selectRaw('? as source_table', [$sourceTable])
            ->whereNotNull("{$table}.organization_id")
            ->whereNotNull("{$table}.contact_id")
            ->whereNotNull("{$table}.created_at");

        if ($organizationId) {
            $query->where("{$table}.organization_id", $organizationId);
        }

        return $query;
    }

    private function flushPendingWindowRows(): void
    {
        if ($this->pendingWindowRows === []) {
            return;
        }

        DB::table('conversation_windows')->insert($this->pendingWindowRows);
        $this->pendingWindowRows = [];
        $this->hasExistingWindows = true;
    }

    private function resetCurrentContactState(): void
    {
        $this->currentOrganizationId = null;
        $this->currentContactId = null;
        $this->currentClosesAt = null;
        $this->existingWindows = [];
        $this->existingWindowIndex = 0;
    }

    private function loadExistingWindows(int $organizationId, int $contactId): void
    {
        $this->currentOrganizationId = $organizationId;
        $this->currentContactId = $contactId;
        $this->currentClosesAt = null;
        $this->existingWindowIndex = 0;
        if (! $this->checkExistingWindows) {
            $this->existingWindows = [];
            return;
        }

        $this->existingWindows = ConversationWindow::query()
            ->where('organization_id', $organizationId)
            ->where('contact_id', $contactId)
            ->orderBy('opened_at')
            ->get(['opened_at', 'closes_at'])
            ->all();
    }

    private function existingWindowFor(Carbon $openedAt): ?object
    {
        while (isset($this->existingWindows[$this->existingWindowIndex])) {
            $window = $this->existingWindows[$this->existingWindowIndex];
            $windowClosesAt = Carbon::parse($window->closes_at);

            if ($windowClosesAt->lte($openedAt)) {
                $this->existingWindowIndex++;
                continue;
            }

            $windowOpenedAt = Carbon::parse($window->opened_at);
            if ($windowOpenedAt->lte($openedAt)) {
                return $window;
            }

            if ($windowOpenedAt->lt($openedAt->copy()->addHours(24))) {
                return $window;
            }

            break;
        }

        return null;
    }

    private function incrementOrganizationStat(int $organizationId, string $key): void
    {
        $this->organizationStats[$organizationId] ??= [
            'organization_id' => $organizationId,
            'organization_name' => null,
            'created' => 0,
            'existing' => 0,
            'inside_window' => 0,
        ];

        $this->organizationStats[$organizationId][$key]++;
    }

    private function printOrganizationStats(): void
    {
        if ($this->organizationStats === []) {
            return;
        }

        $organizationNames = DB::table('organizations')
            ->whereIn('id', array_keys($this->organizationStats))
            ->pluck('name', 'id')
            ->all();

        $rows = collect($this->organizationStats)
            ->sortKeys()
            ->map(function (array $stats) use ($organizationNames): array {
                $organizationId = $stats['organization_id'];
                $createdLabel = (bool) $this->option('dry-run') ? 'would_create' : 'created';

                return [
                    'organization_id' => $organizationId,
                    'organization_name' => $organizationNames[$organizationId] ?? '-',
                    $createdLabel => $stats['created'],
                    'already_existing' => $stats['existing'],
                    'inside_24h_window' => $stats['inside_window'],
                    'total_windows_after_backfill' => $stats['created'] + $stats['existing'],
                ];
            })
            ->values()
            ->all();

        $createdLabel = (bool) $this->option('dry-run') ? 'would_create' : 'created';
        $reportFile = $this->option('report-file');

        if ($reportFile) {
            $this->writeReportFile((string) $reportFile, $rows, $createdLabel);
        }

        $reportLimit = max(1, (int) $this->option('report-limit'));
        $visibleRows = array_slice($rows, 0, $reportLimit);

        $this->table(
            ['organization_id', 'organization_name', $createdLabel, 'already_existing', 'inside_24h_window', 'total_windows_after_backfill'],
            $visibleRows
        );

        if (count($rows) > $reportLimit) {
            $this->line('Showing first ' . $reportLimit . ' organizations out of ' . count($rows) . '. Use --report-file to export the full report.');
        }
    }

    private function writeReportFile(string $path, array $rows, string $createdLabel): void
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            $this->warn("Could not write report file: {$path}");
            return;
        }

        fputcsv($handle, ['organization_id', 'organization_name', $createdLabel, 'already_existing', 'inside_24h_window', 'total_windows_after_backfill']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['organization_id'],
                $row['organization_name'],
                $row[$createdLabel],
                $row['already_existing'],
                $row['inside_24h_window'],
                $row['total_windows_after_backfill'],
            ]);
        }

        fclose($handle);
        $this->info("Full organization report written to: {$path}");
    }
}
