<?php

namespace App\Console\Commands;

use App\Jobs\Webhook\ProcessWebhookJob;
use App\Models\Chat;
use App\Models\WebhookFailure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RetryWebhookDispatchFailures extends Command
{
    protected $signature = 'webhooks:retry-dispatch-failures
        {--id=* : Retry one or more specific webhook_failure ids}
        {--limit=100 : Maximum number of failure records to retry}
        {--organization= : Retry failures for one organization id}
        {--all : Retry all ProcessWebhookJob failure records, not only dispatch-stage failures}
        {--include-retried : Include records that already have attempts greater than zero}
        {--dry-run : Show matching records without dispatching jobs}';

    protected $description = 'Retry WhatsApp webhook failures that were stored because ProcessWebhookJob dispatch failed.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        $organizationId = $this->option('organization') ? (int) $this->option('organization') : null;
        $allFailures = (bool) $this->option('all');
        $includeRetried = (bool) $this->option('include-retried');
        $dryRun = (bool) $this->option('dry-run');

        if (! Schema::hasColumn('webhook_failures', 'context')) {
            $this->error('The webhook_failures.context column is missing. Run php artisan migrate first.');
            return self::FAILURE;
        }

        $query = WebhookFailure::query()
            ->where('job_type', 'ProcessWebhookJob')
            ->whereNotNull('payload')
            ->when(empty($ids) && ! $allFailures, fn ($query) => $query->where('context', 'like', '%"failure_stage":"dispatch"%'))
            ->when(! empty($ids), fn ($query) => $query->whereIn('id', $ids))
            ->when(empty($ids) && ! $includeRetried, fn ($query) => $query->where('attempts', 0))
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->orderBy('id')
            ->limit($limit);

        $failures = $query->get();

        if ($failures->isEmpty()) {
            $this->info('No dispatch failure records matched the retry criteria.');
            return self::SUCCESS;
        }

        $this->info(($dryRun ? 'Found' : 'Retrying').' '.$failures->count().' dispatch failure record(s).');

        $requeued = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($failures as $failure) {
            $payload = $this->normalizeArray($failure->payload);

            if (empty($payload)) {
                $skipped++;
                $this->warn("Skipped #{$failure->id}: payload is empty or invalid.");
                continue;
            }

            $webhookType = $failure->webhook_type ?? ($payload['field'] ?? 'messages');
            $wamId = $this->extractRetryGuardWamId($payload);

            if ($wamId && Chat::where('organization_id', $failure->organization_id)->where('wam_id', $wamId)->exists()) {
                $skipped++;
                $this->line("Skipped #{$failure->id}: chat already exists for wam_id {$wamId}.");
                continue;
            }

            if ($dryRun) {
                $this->line("Would retry #{$failure->id}: org={$failure->organization_id}, type={$webhookType}, wam_id=".($wamId ?: 'n/a'));
                continue;
            }

            try {
                $context = $this->normalizeArray($failure->context);

                dispatch(new ProcessWebhookJob(
                    $payload,
                    $failure->organization_id,
                    $webhookType,
                    array_merge($context, [
                        'webhook_failure_id' => $failure->id,
                        'sample_id' => $failure->sample_id ?? null,
                        'replayed_at' => now()->toIso8601String(),
                        'replayed_by' => 'webhooks:retry-dispatch-failures',
                    ])
                ));

                $failure->update([
                    'attempts' => $failure->attempts + 1,
                ]);

                $requeued++;
                $this->info("Requeued #{$failure->id}: org={$failure->organization_id}, type={$webhookType}, wam_id=".($wamId ?: 'n/a'));
            } catch (\Throwable $exception) {
                $failed++;

                Log::channel('webhook_dispatch_failures')->error('Manual webhook dispatch retry failed', [
                    'webhook_failure_id' => $failure->id,
                    'organization_id' => $failure->organization_id,
                    'webhook_type' => $webhookType,
                    'wam_id' => $wamId,
                    'error' => $exception->getMessage(),
                    'exception' => $exception,
                ]);

                $this->error("Failed #{$failure->id}: ".$exception->getMessage());
            }
        }

        $this->newLine();
        $this->info("Done. Requeued: {$requeued}. Skipped: {$skipped}. Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function extractRetryGuardWamId(array $payload): ?string
    {
        $value = is_array($payload['value'] ?? null) ? $payload['value'] : [];

        foreach (['messages', 'message_echoes'] as $key) {
            $items = is_array($value[$key] ?? null) ? $value[$key] : [];
            $wamId = $items[0]['id'] ?? null;

            if (is_string($wamId) && $wamId !== '') {
                return $wamId;
            }
        }

        return null;
    }
}
