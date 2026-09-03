<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('flow_user_data')
            ->select('contact_id', DB::raw('COUNT(*) as row_count'))
            ->groupBy('contact_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('contact_id')
            ->chunk(200, function ($duplicates): void {
                foreach ($duplicates as $duplicate) {
                    $keepId = DB::table('flow_user_data')
                        ->where('contact_id', $duplicate->contact_id)
                        ->orderByDesc('updated_at')
                        ->orderByDesc('id')
                        ->value('id');

                    $removed = DB::table('flow_user_data')
                        ->where('contact_id', $duplicate->contact_id)
                        ->where('id', '<>', $keepId)
                        ->delete();

                    Log::warning('FlowUserData migration removed duplicate active states', [
                        'contact_id' => $duplicate->contact_id,
                        'kept_id' => $keepId,
                        'removed_count' => $removed,
                    ]);
                }
            });

        Schema::table('flow_user_data', function (Blueprint $table): void {
            $table->unique('contact_id', 'flow_user_data_contact_unique');
        });
    }

    public function down(): void
    {
        Schema::table('flow_user_data', function (Blueprint $table): void {
            $table->dropUnique('flow_user_data_contact_unique');
        });
    }
};
