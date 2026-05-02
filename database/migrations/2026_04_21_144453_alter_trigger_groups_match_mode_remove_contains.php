<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Convert any 'contains' keywords to plain strings and update match_mode
        $triggerGroups = DB::table('trigger_groups')->whereNotNull('keywords')->get();
        foreach ($triggerGroups as $tg) {
            $keywords = json_decode($tg->keywords, true);
            if (is_array($keywords)) {
                // Old format: [{"value": "refund", "mode": "exact"}, ...]
                // New format: ["refund", "help", ...]
                $isOldFormat = !empty($keywords) && isset($keywords[0]['value']);
                if ($isOldFormat) {
                    $plainKeywords = array_map(fn($kw) => $kw['value'] ?? '', $keywords);
                    $plainKeywords = array_filter($plainKeywords, fn($v) => !empty($v));
                    DB::table('trigger_groups')
                        ->where('id', $tg->id)
                        ->update(['keywords' => json_encode(array_values($plainKeywords))]);
                }
            }
        }

        // Change match_mode enum from ('exact','contains','ai') to ('exact','ai')
        DB::statement("ALTER TABLE trigger_groups MODIFY COLUMN match_mode ENUM('exact','ai') NOT NULL DEFAULT 'exact'");

        // Update any existing 'contains' rows to 'exact'
        DB::table('trigger_groups')->where('match_mode', 'contains')->update(['match_mode' => 'exact']);
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE trigger_groups MODIFY COLUMN match_mode ENUM('exact','contains','ai') NOT NULL DEFAULT 'exact'");
    }
};
