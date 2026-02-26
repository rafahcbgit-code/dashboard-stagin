<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\OutboundMessage;
use App\Models\Organization;
use Carbon\Carbon;

class SallaOutboundMessagesSeeder extends Seeder
{
    public function run()
    {
        $org = Organization::first();
        if (!$org) {
            return; // no organization to attach messages to
        }

        // Create a few sample outbound messages within the sample date range
        $dates = [
            '2026-02-22 14:46:00',
            '2026-02-22 14:47:00',
            '2026-02-22 14:48:30',
        ];

        foreach ($dates as $d) {
            OutboundMessage::create([
                'organization_id' => $org->id,
                'source' => 'salla',
                'source_delivery_id' => null,
                'source_event' => 'automation.triggered',
                'automation_key' => 'sample_auto',
                'contact_id' => null,
                'recipient_e164' => '+201234567890',
                'template_id' => null,
                'template_name' => 'Welcome Template',
                'channel' => 'whatsapp',
                'provider' => 'chat_service',
                'status' => 'sent',
                'attempts' => 1,
                'last_error_message' => null,
                'context' => null,
                'created_at' => Carbon::parse($d),
                'updated_at' => Carbon::parse($d),
            ]);
        }
    }
}
