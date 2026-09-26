<?php

namespace App\Console;

use App\Jobs\CreateCampaignLogsJob;
use App\Jobs\Flow\SendFlowRejoinRemindersJob;
use App\Jobs\ProcessCampaignMessagesJob;
use App\Jobs\SyncWhatsappNumberStatusJob;
use App\Models\Campaign;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new CreateCampaignLogsJob(), config('campaigns.queues.logs', 'campaign-logs'))
            ->everyTwoMinutes()
            ->withoutOverlapping();

        // Story 2.1: Dispatch ProcessCampaignMessagesJob per organization (AC-2.1.2, AC-2.1.3)
        // Only dispatch for orgs with ongoing campaigns that have pending/failed logs
        $schedule->call(function () {
            $activeOrgIds = Campaign::where('status', 'ongoing')
                ->whereHas('campaignLogs', function ($logQuery) {
                    $logQuery->whereIn('status', ['pending', 'failed']);
                })
                ->distinct()
                ->pluck('organization_id');

            foreach ($activeOrgIds as $orgId) {
                ProcessCampaignMessagesJob::dispatchForOrganization($orgId)
                    ->onQueue(config('campaigns.queues.dispatchers', 'campaign-dispatchers'));
            }
        })
        ->name('process-campaign-messages-per-org')
        ->everyTwoMinutes()
        ->withoutOverlapping();

        // Safety net for campaigns that reached terminal states while a worker
        // was interrupted or while an older deployment did not close them.
        $schedule->command('campaigns:reconcile-completion')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // Process missed status updates every 3 minutes
        $schedule->job(new \App\Jobs\ProcessMissedStatusUpdatesJob(), 'webhook-status')
            ->everyTenMinutes()
            ->withoutOverlapping();

        // Run ticket creation check every 5 minutes
        $schedule->job(new \App\Jobs\EnsureTicketsExistJob())
            ->everyFiveMinutes()
            ->withoutOverlapping();

        $schedule->job(new SyncWhatsappNumberStatusJob(), 'default', 'redis')
            ->name('sync-whatsapp-number-statuses')
            ->dailyAt('00:00')
            ->timezone('Asia/Damascus')
            ->withoutOverlapping();

        $schedule->command('notifications:reconcile')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('notifications:prune')
            ->daily()
            ->withoutOverlapping();

        // Send flow rejoin reminders every 5 minutes
        $schedule->job(new SendFlowRejoinRemindersJob)
            ->everyFiveMinutes()
            ->withoutOverlapping();

        /*$schedule->command('queue:work --queue=campaign-messages,campaign-logs --stop-when-empty')
            ->everyMinute()
            ->withoutOverlapping();*/

        // Monitor queue health
        $schedule->command('queue:restart')
            ->hourly()
            ->evenInMaintenanceMode();

        // Clean failed jobs table
        $schedule->command('queue:prune-failed --hours=24')
            ->daily()
            ->evenInMaintenanceMode();

        $schedule->command('queue:prune-batches --hours=48 --unfinished=72')
            ->daily();

        // $schedule->command('contacts:cleanup-import-batches --hours=48')
        //     ->daily();

        $schedule->command('model:prune', [
            '--model' => [\App\Models\CampaignLog::class],
            '--hours' => 72,
        ])->daily();

        // // Clean old processed missed status updates (keep for 7 days)
        $schedule->command('model:prune', [
            '--model' => [\App\Models\MissedChatStatusUpdate::class],
            '--hours' => 168, // 7 days
        ])->daily()
            ->when(function () {
                // Only run if there are processed records older than 7 days
                return \App\Models\MissedChatStatusUpdate::where('state', 'processed')
                    ->where('created_at', '<', now()->subDays(7))
                    ->exists();
            });

        // Monitor queue size
        //$schedule->command('monitor:queue-size')->everyFiveMinutes();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
