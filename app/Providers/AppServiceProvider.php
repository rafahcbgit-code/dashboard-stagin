<?php

namespace App\Providers;

use App\Services\WooCommerce\Contracts\WooCommerceIntegrationRepositoryInterface;
use App\Services\WooCommerce\Repositories\Eloquent\WooCommerceIntegrationRepository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            WooCommerceIntegrationRepositoryInterface::class,
            WooCommerceIntegrationRepository::class
        );

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        DB::listen(function (QueryExecuted $query) {
            if ($query->time >= 150) {
                Log::channel('slow_queries')->warning('Slow Database Query', [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time_ms' => $query->time,
                    'connection' => $query->connectionName,
                    'url' => request()?->fullUrl(),
                ]);
            }
        });

        $slowJobStartedAt = [];
        $slowJobThresholdMs = 2000;

        $jobKey = static function (JobProcessing|JobProcessed|JobExceptionOccurred $event): string {
            return $event->job->uuid()
                ?: $event->connectionName.':'.$event->job->getQueue().':'.$event->job->getJobId().':'.spl_object_id($event->job);
        };

        $logSlowJob = function (JobProcessed|JobExceptionOccurred $event, string $status) use (&$slowJobStartedAt, $jobKey, $slowJobThresholdMs): void {
            $key = $jobKey($event);
            $startedAt = $slowJobStartedAt[$key] ?? null;

            unset($slowJobStartedAt[$key]);

            if ($startedAt === null) {
                return;
            }

            $durationMs = round((microtime(true) - $startedAt) * 1000, 2);

            if ($durationMs < $slowJobThresholdMs) {
                return;
            }

            $context = [
                'job' => $event->job->resolveName(),
                'duration_ms' => $durationMs,
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'attempts' => $event->job->attempts(),
                'status' => $status,
                'job_id' => $event->job->getJobId(),
                'uuid' => $event->job->uuid(),
            ];

            if ($event instanceof JobExceptionOccurred) {
                $context['exception'] = get_class($event->exception);
                $context['message'] = $event->exception->getMessage();
            }

            Log::channel('slow_jobs')->warning('Slow Queue Job', $context);
        };

        Queue::before(function (JobProcessing $event) use (&$slowJobStartedAt, $jobKey): void {
            $slowJobStartedAt[$jobKey($event)] = microtime(true);
        });

        Queue::after(function (JobProcessed $event) use ($logSlowJob): void {
            $logSlowJob($event, 'processed');
        });

        Queue::exceptionOccurred(function (JobExceptionOccurred $event) use ($logSlowJob): void {
            $logSlowJob($event, 'exception');
        });
        
        if (!\App::environment('local')) {
            \URL::forceScheme('https');
        }
        
        Inertia::share([
        'contact_phone' => fn () => request()->get('contact_phone'),
        'whatsapp_link' => fn () => request()->get('whatsapp_link'),
        'calendly_url' => fn () => request()->get('calendly_url'),
        'is_trial' => fn () => request()->get('is_trial'),
        ]);
    }
}
