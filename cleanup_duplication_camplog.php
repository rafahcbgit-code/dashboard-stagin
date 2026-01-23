<?php

use Illuminate\Support\Facades\DB;
use App\Models\CampaignLog;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DB::transaction(function() {
    echo "Finding duplicates...\n";
    $duplicates = DB::table('campaign_logs')
        ->select('campaign_id', 'contact_id')
        ->groupBy('campaign_id', 'contact_id')
        ->havingRaw('COUNT(*) > 1')
        ->get();

    echo "Found " . count($duplicates) . " pairs with duplicates.\n";
    
    $totalDeleted = 0;
    foreach ($duplicates as $dup) {
        $ids = DB::table('campaign_logs')
            ->where('campaign_id', $dup->campaign_id)
            ->where('contact_id', $dup->contact_id)
            ->orderBy('id')
            ->pluck('id')
            ->toArray();
        
        // Keep the first one, delete the rest
        array_shift($ids);
        if (!empty($ids)) {
            $deletedCount = DB::table('campaign_logs')->whereIn('id', $ids)->delete();
            $totalDeleted += $deletedCount;
        }
    }
    
    echo "Cleanup complete. Total deleted: $totalDeleted records.\n";
});

