<?php

namespace App\Console\Commands;

use App\Models\ContactImportBatch;
use Illuminate\Console\Command;

class CleanupContactImportBatchesCommand extends Command
{
    protected $signature = 'contacts:cleanup-import-batches {--hours=48 : Delete batches older than N hours}';

    protected $description = 'Delete old temporary contact import batches and rows';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));

        $deleted = ContactImportBatch::query()
            ->where('created_at', '<', now()->subHours($hours))
            ->delete();

        $this->info("Deleted {$deleted} old contact import batches.");

        return self::SUCCESS;
    }
}

