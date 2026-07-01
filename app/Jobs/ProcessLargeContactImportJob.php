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
        $message = $this->summarizeExceptionMessage($exception);

        ContactImportBatch::query()
            ->where('id', $this->batchId)
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $message,
                'updated_at' => now(),
            ]);

        Log::error('Large contact import job failed', [
            'batch_id' => $this->batchId,
            'exception_message' => $message,
            'exception_class' => $exception::class,
        ]);
    }

    private function summarizeExceptionMessage(\Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'Got a packet bigger than')) {
            return 'MySQL max_allowed_packet was exceeded while writing an import chunk.';
        }

        $message = preg_replace('/\s+/', ' ', $message) ?: $exception::class;

        return mb_substr($message, 0, 1000);
    }
}
