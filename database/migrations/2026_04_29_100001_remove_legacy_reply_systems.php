<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Strip legacy reply keys from all organizations' metadata
        $organizations = DB::table('organizations')->whereNotNull('metadata')->get();

        foreach ($organizations as $org) {
            $metadata = json_decode($org->metadata, true);
            $changed = false;

            // Remove global_reply
            if (isset($metadata['global_reply'])) {
                unset($metadata['global_reply']);
                $changed = true;
            }

            // Remove greeting_message
            if (isset($metadata['greeting_message'])) {
                unset($metadata['greeting_message']);
                $changed = true;
            }

            // Remove working_hour_reply (the after-hours reply message)
            if (isset($metadata['working_hour_reply'])) {
                unset($metadata['working_hour_reply']);
                $changed = true;
            }

            // Remove Basic Replies and AI Reply Assistant from response_sequence
            if (isset($metadata['automation']['response_sequence'])) {
                $metadata['automation']['response_sequence'] = array_values(
                    array_filter($metadata['automation']['response_sequence'], function ($item) {
                        return $item !== 'Basic Replies' && $item !== 'AI Reply Assistant';
                    })
                );

                // If empty, remove the automation key entirely
                if (empty($metadata['automation']['response_sequence'])) {
                    unset($metadata['automation']['response_sequence']);
                }
                if (empty($metadata['automation'])) {
                    unset($metadata['automation']);
                }
                $changed = true;
            }

            if ($changed) {
                DB::table('organizations')
                    ->where('id', $org->id)
                    ->update(['metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE)]);
            }
        }

        // Drop the auto_replies table
        Schema::dropIfExists('auto_replies');
    }

    public function down(): void
    {
        // Cannot reverse this migration — data is deleted
    }
};