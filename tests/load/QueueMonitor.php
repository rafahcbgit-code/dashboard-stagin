<?php

/**
 * Queue Depth Monitor for Load Testing
 *
 * AC-1.6.2: All 100 messages processed within 5 seconds total
 *
 * Usage:
 *   php tests/load/QueueMonitor.php --duration=60 --interval=1
 *
 * This script monitors the Laravel queue during load testing to:
 * - Track queue depth over time (jobs pending)
 * - Measure processing rate (jobs/second)
 * - Detect backlog accumulation
 * - Record when queue clears after burst
 */

require_once __DIR__.'/../../vendor/autoload.php';

use Illuminate\Support\Facades\DB;

// Bootstrap Laravel
$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

class QueueMonitor
{
    private int $duration;

    private int $interval;

    private bool $verbose;

    private string $resultsDir;

    private array $measurements = [];

    private float $startTime;

    public function __construct(array $options)
    {
        $this->duration = (int) ($options['duration'] ?? 60);
        $this->interval = (int) ($options['interval'] ?? 1);
        $this->verbose = isset($options['verbose']);
        $this->resultsDir = __DIR__.'/results';
    }

    public function run(): void
    {
        $this->printHeader();

        if (! is_dir($this->resultsDir)) {
            mkdir($this->resultsDir, 0755, true);
        }

        $this->startTime = microtime(true);
        $endTime = $this->startTime + $this->duration;
        $iteration = 0;

        echo "Monitoring queue for {$this->duration} seconds...\n";
        echo "Press Ctrl+C to stop early.\n\n";

        // Header
        printf("%-10s | %-8s | %-10s | %-10s | %-10s\n",
            'Time (s)', 'Pending', 'Reserved', 'Failed', 'Rate/s'
        );
        echo str_repeat('-', 60)."\n";

        $previousProcessed = $this->getTotalProcessed();

        while (microtime(true) < $endTime) {
            $elapsedSeconds = microtime(true) - $this->startTime;

            // Get queue metrics
            $pending = $this->getPendingJobCount();
            $reserved = $this->getReservedJobCount();
            $failed = $this->getFailedJobCount();
            $currentProcessed = $this->getTotalProcessed();

            // Calculate processing rate
            $processedThisInterval = $currentProcessed - $previousProcessed;
            $ratePerSecond = $processedThisInterval / max(1, $this->interval);
            $previousProcessed = $currentProcessed;

            // Store measurement
            $this->measurements[] = [
                'timestamp' => date('c'),
                'elapsed_seconds' => round($elapsedSeconds, 2),
                'pending' => $pending,
                'reserved' => $reserved,
                'failed' => $failed,
                'processed_interval' => $processedThisInterval,
                'rate_per_second' => round($ratePerSecond, 2),
            ];

            // Output
            printf("%-10.2f | %-8d | %-10d | %-10d | %-10.2f\n",
                $elapsedSeconds, $pending, $reserved, $failed, $ratePerSecond
            );

            $iteration++;
            sleep($this->interval);
        }

        $this->printSummary();
        $this->saveResults();
    }

    private function getPendingJobCount(): int
    {
        // For database queue driver
        try {
            return DB::table('jobs')
                ->where('queue', 'webhook-high')
                ->whereNull('reserved_at')
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getReservedJobCount(): int
    {
        try {
            return DB::table('jobs')
                ->where('queue', 'webhook-high')
                ->whereNotNull('reserved_at')
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getFailedJobCount(): int
    {
        try {
            return DB::table('failed_jobs')
                ->where('queue', 'webhook-high')
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getTotalProcessed(): int
    {
        // Count total chats created as proxy for processed webhooks
        try {
            return DB::table('chats')->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function printHeader(): void
    {
        echo "\n";
        echo str_repeat('=', 60)."\n";
        echo "QUEUE DEPTH MONITOR - Story 1.6\n";
        echo str_repeat('=', 60)."\n\n";
    }

    private function printSummary(): void
    {
        if (empty($this->measurements)) {
            return;
        }

        $totalDuration = microtime(true) - $this->startTime;
        $maxPending = max(array_column($this->measurements, 'pending'));
        $avgPending = array_sum(array_column($this->measurements, 'pending')) / count($this->measurements);
        $maxRate = max(array_column($this->measurements, 'rate_per_second'));
        $avgRate = array_sum(array_column($this->measurements, 'rate_per_second')) / count($this->measurements);
        $totalFailed = end($this->measurements)['failed'];

        // Find when queue cleared (first time pending = 0 after having jobs)
        $clearTime = null;
        $hadJobs = false;
        foreach ($this->measurements as $m) {
            if ($m['pending'] > 0) {
                $hadJobs = true;
            }
            if ($hadJobs && $m['pending'] == 0) {
                $clearTime = $m['elapsed_seconds'];
                break;
            }
        }

        echo "\n".str_repeat('=', 60)."\n";
        echo "MONITORING SUMMARY\n";
        echo str_repeat('=', 60)."\n";
        echo 'Duration: '.round($totalDuration, 2)."s\n";
        echo 'Samples: '.count($this->measurements)."\n\n";

        echo "Queue Depth:\n";
        echo "  Max pending:     {$maxPending}\n";
        echo '  Avg pending:     '.round($avgPending, 2)."\n";
        if ($clearTime) {
            echo "  Queue cleared at: {$clearTime}s\n";
        }

        echo "\nProcessing Rate:\n";
        echo "  Max rate:        {$maxRate} jobs/s\n";
        echo '  Avg rate:        '.round($avgRate, 2)." jobs/s\n";

        echo "\nFailures:\n";
        echo "  Total failed:    {$totalFailed}\n";

        // AC-1.6.2 check
        echo "\nAC-1.6.2 VERIFICATION:\n";
        if ($clearTime && $clearTime <= 5) {
            echo "  ✓ Queue cleared within 5 seconds ({$clearTime}s)\n";
        } elseif ($clearTime) {
            echo "  ✗ Queue took {$clearTime}s to clear (target: 5s)\n";
        } else {
            echo "  ? Queue did not clear during monitoring\n";
        }
    }

    private function saveResults(): void
    {
        $timestamp = date('Y-m-d_His');
        $filename = "{$this->resultsDir}/queue_monitor_{$timestamp}.json";

        $results = [
            'timestamp' => date('c'),
            'duration_seconds' => $this->duration,
            'interval_seconds' => $this->interval,
            'measurements' => $this->measurements,
            'summary' => [
                'sample_count' => count($this->measurements),
                'max_pending' => max(array_column($this->measurements, 'pending')),
                'max_rate_per_second' => max(array_column($this->measurements, 'rate_per_second')),
                'total_failed' => end($this->measurements)['failed'] ?? 0,
            ],
        ];

        file_put_contents($filename, json_encode($results, JSON_PRETTY_PRINT));
        echo "\nResults saved to: {$filename}\n";
    }
}

// Parse command line options
$options = getopt('', [
    'duration:',
    'interval:',
    'verbose',
    'help',
]);

if (isset($options['help'])) {
    echo <<<'HELP'
Queue Depth Monitor for Load Testing

Usage:
  php tests/load/QueueMonitor.php [options]

Options:
  --duration    Monitoring duration in seconds (default: 60)
  --interval    Measurement interval in seconds (default: 1)
  --verbose     Show detailed output
  --help        Show this help message

HELP;
    exit(0);
}

$monitor = new QueueMonitor($options);
$monitor->run();
