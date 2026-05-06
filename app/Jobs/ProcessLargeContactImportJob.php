<?php

namespace App\Jobs;

use App\Models\ContactImportBatch;
use App\Services\ContactImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessLargeContactImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(private readonly int $batchId)
    {
        $this->onQueue('contact-imports');
    }

    public function handle(ContactImportService $importService): void
    {
        $batch = ContactImportBatch::query()->findOrFail($this->batchId);

        if ($batch->status === 'committed') {
            return;
        }

        $importService->processBackgroundImport($batch);
    }

    public function failed(\Throwable $exception): void
    {
        ContactImportBatch::query()
            ->where('id', $this->batchId)
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
                'updated_at' => now(),
            ]);

        Log::error('Large contact import job failed', [
            'batch_id' => $this->batchId,
            'exception_message' => $exception->getMessage(),
            'exception_class' => $exception::class,
        ]);
    }
}
