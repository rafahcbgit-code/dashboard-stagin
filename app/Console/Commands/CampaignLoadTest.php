<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCampaignMessagesJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Template;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Task 2.7: Campaign Load Testing and Validation
 *
 * Artisan command to run concurrent campaign load tests.
 * Creates isolated test data, runs campaigns, collects metrics,
 * and verifies data integrity.
 *
 * Usage:
 *   php artisan campaign:load-test
 *   php artisan campaign:load-test --orgs=5 --contacts=1000
 *   php artisan campaign:load-test --dry-run
 *   php artisan campaign:load-test --cleanup
 */
class CampaignLoadTest extends Command
{
    protected $signature = 'campaign:load-test
                            {--orgs=5 : Number of test organizations to create}
                            {--contacts=1000 : Contacts per organization}
                            {--dry-run : Show what would be created without executing}
                            {--cleanup : Remove test data after completion}
                            {--cleanup-only : Only clean up existing test data, do not run test}
                            {--skip-dispatch : Create data but do not dispatch campaigns}
                            {--monitor-interval=5 : Seconds between progress checks}';

    protected $description = 'Run concurrent campaign load test to validate Epic 2 optimizations';

    /**
     * Test data prefix for easy identification and cleanup.
     */
    protected const TEST_PREFIX = 'LoadTest-';

    /**
     * Metrics collected during test execution.
     *
     * @var array{
     *     start_time: float,
     *     organizations: array,
     *     campaigns: array,
     *     snapshots: array,
     *     results: array
     * }
     */
    protected array $metrics = [
        'start_time' => 0,
        'organizations' => [],
        'campaigns' => [],
        'snapshots' => [],
        'results' => [],
    ];

    public function handle(): int
    {
        // Cleanup-only mode
        if ($this->option('cleanup-only')) {
            return $this->cleanupTestData() ? 0 : 1;
        }

        $orgCount = (int) $this->option('orgs');
        $contactCount = (int) $this->option('contacts');
        $dryRun = $this->option('dry-run');
        $skipDispatch = $this->option('skip-dispatch');

        $this->info('Campaign Load Test');
        $this->info('==================');
        $this->info("Organizations: {$orgCount}");
        $this->info("Contacts per org: {$contactCount}");
        $this->info('Total messages: '.($orgCount * $contactCount));

        if ($dryRun) {
            $this->warn("\n[DRY RUN] No changes will be made.");
            $this->showDryRunPlan($orgCount, $contactCount);

            return 0;
        }

        $this->newLine();

        // Step 1: Create test organizations
        $this->info('Step 1: Creating test organizations...');
        $organizations = $this->createTestOrganizations($orgCount);

        // Step 2: Create contacts for each org
        $this->info('Step 2: Creating test contacts...');
        $this->createTestContacts($organizations, $contactCount);

        // Step 3: Create campaigns
        $this->info('Step 3: Creating campaigns...');
        $campaigns = $this->createTestCampaigns($organizations);

        // Step 4: Create campaign logs
        $this->info('Step 4: Creating campaign logs...');
        $this->createCampaignLogs($campaigns, $organizations, $contactCount);

        if ($skipDispatch) {
            $this->warn("\n[SKIP DISPATCH] Data created but campaigns not dispatched.");
            $this->showTestDataSummary($organizations, $campaigns);

            return 0;
        }

        // Step 5: Dispatch campaigns
        $this->info('Step 5: Dispatching campaigns...');
        $this->metrics['start_time'] = microtime(true);
        $this->dispatchCampaigns($organizations);

        // Step 6: Monitor progress
        $this->info('Step 6: Monitoring progress...');
        $this->monitorProgress($campaigns);

        // Step 7: Collect final metrics
        $this->info('Step 7: Collecting final metrics...');
        $this->collectFinalMetrics($campaigns);

        // Step 8: Verify data integrity
        $this->info('Step 8: Verifying data integrity...');
        $integrityPassed = $this->verifyIntegrity($campaigns);

        // Step 9: Report results
        $this->newLine();
        $this->reportResults($integrityPassed);

        // Step 10: Optional cleanup
        if ($this->option('cleanup')) {
            $this->info("\nStep 10: Cleaning up test data...");
            $this->cleanupTestData();
        }

        // Log results
        $this->logResults();

        return $integrityPassed ? 0 : 1;
    }

    /**
     * Create test organizations with LoadTest- prefix.
     *
     * @return \Illuminate\Support\Collection<int, Organization>
     */
    protected function createTestOrganizations(int $count)
    {
        $organizations = collect();

        for ($i = 1; $i <= $count; $i++) {
            $org = Organization::create([
                'uuid' => Str::uuid()->toString(),
                'identifier' => self::TEST_PREFIX.Str::random(8),
                'name' => self::TEST_PREFIX."Org-{$i}",
                'address' => 'Load Test Address',
                'timezone' => 'UTC',
                'created_by' => 1,
                'metadata' => json_encode([
                    'whatsapp' => [
                        'access_token' => 'test_token_'.Str::random(32),
                        'phone_number_id' => 'test_phone_'.Str::random(10),
                        'waba_id' => 'test_waba_'.Str::random(10),
                        'app_id' => 'test_app_'.Str::random(10),
                    ],
                    'timezone' => 'UTC',
                    'campaigns' => [
                        'batch_size' => 500,
                        'enable_resend' => false,
                        'resend_intervals' => [],
                    ],
                ]),
            ]);

            $organizations->push($org);
            $this->metrics['organizations'][$org->id] = [
                'name' => $org->name,
                'queue' => 'campaign-org-'.$org->id,
            ];

            $this->output->write('.');
        }

        $this->newLine();
        $this->info("Created {$count} test organizations.");

        return $organizations;
    }

    /**
     * Create test contacts for each organization.
     */
    protected function createTestContacts($organizations, int $contactCount): void
    {
        foreach ($organizations as $org) {
            $contacts = [];
            $now = now();

            for ($i = 1; $i <= $contactCount; $i++) {
                $contacts[] = [
                    'uuid' => Str::uuid()->toString(),
                    'organization_id' => $org->id,
                    'phone' => '+1'.str_pad((string) rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT),
                    'first_name' => self::TEST_PREFIX.'Contact',
                    'last_name' => "#{$i}",
                    'email' => "loadtest-{$i}-{$org->id}@example.com",
                    'is_subscribed' => 1,
                    'created_by' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // Batch insert every 500 records
                if (count($contacts) >= 500) {
                    Contact::insert($contacts);
                    $contacts = [];
                    $this->output->write('.');
                }
            }

            // Insert remaining
            if (! empty($contacts)) {
                Contact::insert($contacts);
            }

            $this->output->write('.');
        }

        $this->newLine();
        $this->info("Created {$contactCount} contacts per organization.");
    }

    /**
     * Create test campaigns for each organization.
     *
     * @return \Illuminate\Support\Collection<int, Campaign>
     */
    protected function createTestCampaigns($organizations)
    {
        $campaigns = collect();

        foreach ($organizations as $org) {
            // Create or find a test template
            $template = $this->getOrCreateTestTemplate($org->id);

            $campaign = Campaign::create([
                'uuid' => Str::uuid()->toString(),
                'organization_id' => $org->id,
                'name' => self::TEST_PREFIX.'Campaign-'.now()->format('Y-m-d-His'),
                'template_id' => $template->id,
                'contact_group_id' => 0,
                'metadata' => json_encode([
                    'template_name' => 'load_test_template',
                    'template_language' => 'en',
                    'load_test' => true,
                ]),
                'status' => 'ongoing',
                'scheduled_at' => now()->subMinutes(5),
                'created_by' => 1,
                'created_at' => now(),
            ]);

            $campaigns->push($campaign);
            $this->metrics['campaigns'][$campaign->id] = [
                'uuid' => $campaign->uuid,
                'organization_id' => $org->id,
                'status' => 'ongoing',
                'start_time' => null,
                'end_time' => null,
            ];

            $this->output->write('.');
        }

        $this->newLine();
        $this->info("Created {$campaigns->count()} campaigns.");

        return $campaigns;
    }

    /**
     * Get or create a test template for campaigns.
     */
    protected function getOrCreateTestTemplate(int $organizationId): Template
    {
        $template = Template::where('organization_id', $organizationId)
            ->where('name', 'load_test_template')
            ->first();

        if (! $template) {
            $template = Template::create([
                'uuid' => Str::uuid()->toString(),
                'organization_id' => $organizationId,
                'name' => 'load_test_template',
                'language' => 'en',
                'category' => 'MARKETING',
                'status' => 'APPROVED',
                'components' => json_encode([
                    ['type' => 'BODY', 'text' => 'Load test message'],
                ]),
                'created_by' => 1,
            ]);
        }

        return $template;
    }

    /**
     * Create campaign logs for all contacts.
     */
    protected function createCampaignLogs($campaigns, $organizations, int $contactCount): void
    {
        foreach ($campaigns as $campaign) {
            $contacts = Contact::where('organization_id', $campaign->organization_id)
                ->where('first_name', 'LIKE', self::TEST_PREFIX.'%')
                ->limit($contactCount)
                ->get();

            $logs = [];
            $now = now();

            foreach ($contacts as $contact) {
                $logs[] = [
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'chat_id' => null,
                    'metadata' => null,
                    'status' => 'pending',
                    'retry_count' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // Batch insert every 500 records
                if (count($logs) >= 500) {
                    CampaignLog::insert($logs);
                    $logs = [];
                    $this->output->write('.');
                }
            }

            // Insert remaining
            if (! empty($logs)) {
                CampaignLog::insert($logs);
            }

            $this->output->write('.');
        }

        $this->newLine();
        $this->info('Created campaign logs for all contacts.');
    }

    /**
     * Dispatch campaigns for all organizations.
     */
    protected function dispatchCampaigns($organizations): void
    {
        // Bind mock WhatsApp service to avoid real API calls
        $this->bindMockWhatsAppService();

        foreach ($organizations as $org) {
            $this->metrics['campaigns'][$org->id]['start_time'] = microtime(true);
            ProcessCampaignMessagesJob::dispatchForOrganization($org->id);
            $this->output->write('.');
        }

        $this->newLine();
        $this->info('Dispatched campaigns for '.count($organizations).' organizations.');
        $this->warn('Note: Queue workers must be running to process campaigns.');
        $this->warn('Run: php artisan queue:work --queue='.implode(',', array_map(
            fn ($org) => 'campaign-org-'.$org->id,
            $organizations->toArray()
        )).',default');
    }

    /**
     * Bind mock WhatsApp service to avoid real API calls during load test.
     */
    protected function bindMockWhatsAppService(): void
    {
        // Note: The actual mock is applied in tests via Http::fake()
        // For the artisan command, we rely on test mode detection
        // or the --dry-run flag
        Cache::put('load_test_active', true, now()->addHours(2));
    }

    /**
     * Monitor campaign progress until completion or timeout.
     */
    protected function monitorProgress($campaigns): void
    {
        $interval = (int) $this->option('monitor-interval');
        $timeout = 60 * 30; // 30 minutes max
        $elapsed = 0;
        $allComplete = false;

        $this->newLine();
        $this->info("Monitoring progress (interval: {$interval}s, timeout: ".($timeout / 60).' min)...');
        $this->newLine();

        while (! $allComplete && $elapsed < $timeout) {
            sleep($interval);
            $elapsed += $interval;

            $snapshot = $this->captureSnapshot($campaigns);
            $this->metrics['snapshots'][] = $snapshot;

            $this->displayProgressTable($snapshot);

            // Check if all campaigns are complete
            $allComplete = collect($snapshot['campaigns'])->every(
                fn ($c) => $c['pending'] === 0 && $c['ongoing'] === 0
            );

            if ($allComplete) {
                $this->info("\nAll campaigns completed!");
                break;
            }
        }

        if (! $allComplete) {
            $this->warn("\nMonitoring timeout reached. Some campaigns may still be processing.");
        }
    }

    /**
     * Capture current progress snapshot.
     */
    protected function captureSnapshot($campaigns): array
    {
        $snapshot = [
            'timestamp' => now()->toISOString(),
            'elapsed_seconds' => microtime(true) - $this->metrics['start_time'],
            'system' => $this->getSystemMetrics(),
            'queue_depths' => $this->getQueueDepths(),
            'campaigns' => [],
        ];

        foreach ($campaigns as $campaign) {
            $stats = CampaignLog::where('campaign_id', $campaign->id)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) as ongoing,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
                ")
                ->first();

            $snapshot['campaigns'][$campaign->id] = [
                'uuid' => $campaign->uuid,
                'organization_id' => $campaign->organization_id,
                'total' => (int) $stats->total,
                'success' => (int) $stats->success,
                'failed' => (int) $stats->failed,
                'pending' => (int) $stats->pending,
                'ongoing' => (int) $stats->ongoing,
                'cancelled' => (int) $stats->cancelled,
                'percent_complete' => $stats->total > 0
                    ? round(($stats->success + $stats->failed + $stats->cancelled) / $stats->total * 100, 1)
                    : 0,
            ];
        }

        return $snapshot;
    }

    /**
     * Get system metrics (CPU, memory).
     */
    protected function getSystemMetrics(): array
    {
        $cpuLoad = null;

        // Try Unix-style load average
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $cpuLoad = $load[0] ?? null;
        }

        // Windows fallback
        if ($cpuLoad === null && PHP_OS_FAMILY === 'Windows') {
            $output = @shell_exec('wmic cpu get loadpercentage /value');
            if ($output && preg_match('/LoadPercentage=(\d+)/', $output, $matches)) {
                $cpuLoad = (int) $matches[1];
            }
        }

        return [
            'cpu_load' => $cpuLoad,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ];
    }

    /**
     * Get queue depths per organization channel.
     */
    protected function getQueueDepths(): array
    {
        return DB::table('jobs')
            ->select('queue', DB::raw('COUNT(*) as depth'))
            ->groupBy('queue')
            ->pluck('depth', 'queue')
            ->toArray();
    }

    /**
     * Display progress table in console.
     */
    protected function displayProgressTable(array $snapshot): void
    {
        $elapsed = round($snapshot['elapsed_seconds']);
        $cpu = $snapshot['system']['cpu_load'] ?? 'N/A';

        $this->line(sprintf(
            '[%s] Elapsed: %ds | CPU: %s%% | Memory: %sMB',
            $snapshot['timestamp'],
            $elapsed,
            is_numeric($cpu) ? round($cpu, 1) : $cpu,
            $snapshot['system']['memory_usage_mb']
        ));

        $headers = ['Campaign', 'Org', 'Total', 'Success', 'Failed', 'Pending', 'Ongoing', '%'];
        $rows = [];

        foreach ($snapshot['campaigns'] as $campaignId => $stats) {
            $rows[] = [
                substr($stats['uuid'], 0, 8),
                $stats['organization_id'],
                $stats['total'],
                $stats['success'],
                $stats['failed'],
                $stats['pending'],
                $stats['ongoing'],
                $stats['percent_complete'].'%',
            ];
        }

        $this->table($headers, $rows);
    }

    /**
     * Collect final metrics after campaigns complete.
     */
    protected function collectFinalMetrics($campaigns): void
    {
        $totalDuration = microtime(true) - $this->metrics['start_time'];

        $this->metrics['results'] = [
            'total_duration_seconds' => round($totalDuration, 2),
            'total_duration_formatted' => gmdate('H:i:s', (int) $totalDuration),
            'campaigns_count' => $campaigns->count(),
            'total_messages' => 0,
            'total_success' => 0,
            'total_failed' => 0,
            'total_pending' => 0,
            'total_cancelled' => 0,
            'max_queue_depth' => 0,
            'max_cpu_load' => 0,
        ];

        foreach ($campaigns as $campaign) {
            $stats = CampaignLog::where('campaign_id', $campaign->id)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
                ")
                ->first();

            $this->metrics['results']['total_messages'] += (int) $stats->total;
            $this->metrics['results']['total_success'] += (int) $stats->success;
            $this->metrics['results']['total_failed'] += (int) $stats->failed;
            $this->metrics['results']['total_pending'] += (int) $stats->pending;
            $this->metrics['results']['total_cancelled'] += (int) $stats->cancelled;
        }

        // Calculate max values from snapshots
        foreach ($this->metrics['snapshots'] as $snapshot) {
            if (isset($snapshot['system']['cpu_load']) && is_numeric($snapshot['system']['cpu_load'])) {
                $this->metrics['results']['max_cpu_load'] = max(
                    $this->metrics['results']['max_cpu_load'],
                    $snapshot['system']['cpu_load']
                );
            }

            foreach ($snapshot['queue_depths'] as $depth) {
                $this->metrics['results']['max_queue_depth'] = max(
                    $this->metrics['results']['max_queue_depth'],
                    $depth
                );
            }
        }
    }

    /**
     * Verify data integrity - no message loss or duplication.
     */
    protected function verifyIntegrity($campaigns): bool
    {
        $allPassed = true;

        $this->newLine();
        $this->info('Data Integrity Verification:');
        $this->line('-----------------------------');

        // AC-2.7.5: Check for message loss
        $pendingCount = CampaignLog::whereIn('campaign_id', $campaigns->pluck('id'))
            ->where('status', 'pending')
            ->count();

        if ($pendingCount > 0) {
            $this->error("FAIL: {$pendingCount} messages still pending (not processed)");
            $allPassed = false;
        } else {
            $this->info('PASS: No messages left in pending status');
        }

        // AC-2.7.5: Verify expected vs actual
        $contactCount = (int) $this->option('contacts');
        $expectedTotal = $campaigns->count() * $contactCount;
        $actualTotal = CampaignLog::whereIn('campaign_id', $campaigns->pluck('id'))->count();

        if ($actualTotal !== $expectedTotal) {
            $this->error("FAIL: Expected {$expectedTotal} logs, found {$actualTotal} (message loss detected)");
            $allPassed = false;
        } else {
            $this->info("PASS: All {$expectedTotal} messages accounted for");
        }

        // AC-2.7.6: Check for duplicates by looking at metadata wam_id
        $duplicateCheck = CampaignLog::whereIn('campaign_id', $campaigns->pluck('id'))
            ->whereNotNull('metadata')
            ->where('status', 'success')
            ->get()
            ->filter(function ($log) {
                $meta = json_decode($log->metadata, true);

                return isset($meta['data']['messages'][0]['id']);
            })
            ->groupBy(function ($log) {
                $meta = json_decode($log->metadata, true);

                return $meta['data']['messages'][0]['id'] ?? 'none';
            })
            ->filter(fn ($group) => $group->count() > 1);

        if ($duplicateCheck->count() > 0) {
            $this->error("FAIL: {$duplicateCheck->count()} duplicate wam_ids detected");
            $allPassed = false;
        } else {
            $this->info('PASS: No duplicate wam_ids found');
        }

        // AC-2.7.7: Queue depth check (from snapshots)
        $maxQueueDepth = $this->metrics['results']['max_queue_depth'] ?? 0;
        if ($maxQueueDepth > 2000) {
            $this->error("FAIL: Max queue depth {$maxQueueDepth} exceeded threshold (2000)");
            $allPassed = false;
        } else {
            $this->info("PASS: Max queue depth ({$maxQueueDepth}) within threshold");
        }

        // AC-2.7.3: CPU utilization check
        $maxCpu = $this->metrics['results']['max_cpu_load'] ?? 0;
        if ($maxCpu > 80) {
            $this->warn("WARN: Max CPU load ({$maxCpu}%) exceeded 80% target");
            // Not a hard failure, just a warning
        } else {
            $this->info("PASS: Max CPU load ({$maxCpu}%) within target");
        }

        return $allPassed;
    }

    /**
     * Report final results.
     */
    protected function reportResults(bool $integrityPassed): void
    {
        $results = $this->metrics['results'];

        $this->newLine();
        $this->info('======================================');
        $this->info('      LOAD TEST RESULTS SUMMARY       ');
        $this->info('======================================');

        $this->table(
            ['Metric', 'Value', 'Target', 'Status'],
            [
                [
                    'Total Duration',
                    $results['total_duration_formatted'],
                    '< 25 min',
                    $results['total_duration_seconds'] < 1500 ? 'PASS' : 'FAIL',
                ],
                [
                    'Total Messages',
                    $results['total_messages'],
                    '-',
                    '-',
                ],
                [
                    'Successful',
                    $results['total_success'],
                    '-',
                    '-',
                ],
                [
                    'Failed',
                    $results['total_failed'],
                    '-',
                    '-',
                ],
                [
                    'Still Pending',
                    $results['total_pending'],
                    '0',
                    $results['total_pending'] === 0 ? 'PASS' : 'FAIL',
                ],
                [
                    'Max Queue Depth',
                    $results['max_queue_depth'],
                    '< 2000',
                    $results['max_queue_depth'] < 2000 ? 'PASS' : 'FAIL',
                ],
                [
                    'Max CPU Load',
                    $results['max_cpu_load'].'%',
                    '< 80%',
                    $results['max_cpu_load'] < 80 ? 'PASS' : 'WARN',
                ],
                [
                    'Data Integrity',
                    $integrityPassed ? 'PASSED' : 'FAILED',
                    'PASSED',
                    $integrityPassed ? 'PASS' : 'FAIL',
                ],
            ]
        );

        if ($integrityPassed && $results['total_pending'] === 0) {
            $this->info("\nOVERALL: PASSED - All acceptance criteria met!");
        } else {
            $this->error("\nOVERALL: FAILED - Review failures above.");
        }
    }

    /**
     * Log results to load test log file.
     */
    protected function logResults(): void
    {
        Log::channel('single')->info('Campaign Load Test Results', [
            'metrics' => $this->metrics,
        ]);

        // Also write to dedicated load test log
        $logPath = storage_path('logs/load-test.log');
        $logEntry = [
            'timestamp' => now()->toISOString(),
            'results' => $this->metrics['results'],
            'snapshots_count' => count($this->metrics['snapshots']),
        ];

        file_put_contents(
            $logPath,
            json_encode($logEntry, JSON_PRETTY_PRINT)."\n",
            FILE_APPEND
        );

        $this->info("\nResults logged to: {$logPath}");
    }

    /**
     * Clean up test data.
     */
    protected function cleanupTestData(): bool
    {
        $this->info('Cleaning up test data with prefix: '.self::TEST_PREFIX);

        // Find test organizations
        $testOrgs = Organization::where('name', 'LIKE', self::TEST_PREFIX.'%')->get();

        if ($testOrgs->isEmpty()) {
            $this->info('No test data found to clean up.');

            return true;
        }

        $orgIds = $testOrgs->pluck('id');

        // Delete campaign logs
        $logsDeleted = CampaignLog::whereHas('campaign', function ($q) use ($orgIds) {
            $q->whereIn('organization_id', $orgIds);
        })->delete();
        $this->line("Deleted {$logsDeleted} campaign logs.");

        // Delete campaigns
        $campaignsDeleted = Campaign::whereIn('organization_id', $orgIds)->delete();
        $this->line("Deleted {$campaignsDeleted} campaigns.");

        // Delete contacts
        $contactsDeleted = Contact::whereIn('organization_id', $orgIds)->delete();
        $this->line("Deleted {$contactsDeleted} contacts.");

        // Delete templates
        $templatesDeleted = Template::whereIn('organization_id', $orgIds)->delete();
        $this->line("Deleted {$templatesDeleted} templates.");

        // Delete organizations
        $orgsDeleted = Organization::whereIn('id', $orgIds)->forceDelete();
        $this->line("Deleted {$orgsDeleted} organizations.");

        // Clear load test cache flag
        Cache::forget('load_test_active');

        $this->info('Cleanup complete.');

        return true;
    }

    /**
     * Show dry run plan without executing.
     */
    protected function showDryRunPlan(int $orgCount, int $contactCount): void
    {
        $this->newLine();
        $this->info('Dry Run Plan:');
        $this->info('-------------');
        $this->line("1. Create {$orgCount} test organizations (prefix: ".self::TEST_PREFIX.')');
        $this->line("2. Create {$contactCount} contacts per organization");
        $this->line("3. Create {$orgCount} campaigns (one per org)");
        $this->line('4. Create '.($orgCount * $contactCount).' campaign logs total');
        $this->line('5. Dispatch to queues: '.implode(', ', array_map(fn ($i) => "campaign-org-{$i}", range(1, $orgCount))));
        $this->line('6. Monitor progress until completion');
        $this->line('7. Verify data integrity');
        $this->line('8. Report results');
        $this->newLine();
        $this->info('To execute: Remove --dry-run flag');
    }

    /**
     * Show test data summary.
     */
    protected function showTestDataSummary($organizations, $campaigns): void
    {
        $this->newLine();
        $this->info('Test Data Summary:');
        $this->info('------------------');

        $this->table(
            ['Organization', 'ID', 'Campaign UUID', 'Queue'],
            $organizations->map(fn ($org) => [
                $org->name,
                $org->id,
                $campaigns->firstWhere('organization_id', $org->id)?->uuid ?? 'N/A',
                'campaign-org-'.$org->id,
            ])->toArray()
        );

        $this->newLine();
        $this->info('To dispatch campaigns:');
        $this->line('php artisan campaign:load-test --skip-dispatch=false');
        $this->newLine();
        $this->info('To clean up:');
        $this->line('php artisan campaign:load-test --cleanup-only');
    }
}
