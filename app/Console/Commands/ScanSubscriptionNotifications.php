<?php

namespace App\Console\Commands;

use App\Services\ManagedNotifications\SubscriptionNotificationScanner;
use Illuminate\Console\Command;

class ScanSubscriptionNotifications extends Command
{
    protected $signature = 'notifications:subscription-scan {--usage : Run resource threshold checks only}';

    protected $description = 'Project subscription changes and check subscription notification due dates';

    public function handle(SubscriptionNotificationScanner $scanner): int
    {
        if ($this->option('usage')) {
            $scanner->scanUsage();
            return self::SUCCESS;
        }

        $scanner->queuePlanChanges();
        $scanner->projectEvents();
        $scanner->scanDates();

        return self::SUCCESS;
    }
}
