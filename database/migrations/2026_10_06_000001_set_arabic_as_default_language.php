<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $arabicLanguage = DB::table('languages')
            ->where('code', 'ar')
            ->orderBy('id')
            ->first();

        if ($arabicLanguage) {
            DB::table('languages')
                ->where('id', $arabicLanguage->id)
                ->update([
                    'name' => 'العربية',
                    'status' => 'active',
                    'is_rtl' => true,
                    'deleted_at' => null,
                    'deleted_by' => null,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('languages')->insert([
                'name' => 'العربية',
                'code' => 'ar',
                'status' => 'active',
                'is_rtl' => true,
                'deleted_at' => null,
                'deleted_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('settings')->updateOrInsert(
            ['key' => 'default_language'],
            ['value' => 'ar']
        );

        Cache::forget('settings:default_language');
        Cache::forget('locales:active-codes');
        Cache::forget('languages:active:shared');
        Cache::forget('languages:current:ar');
    }

    public function down(): void
    {
        // Keep the selected language and locale catalog intact when rolling back.
    }
};
