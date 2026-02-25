<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SallaWebhookDelivery;
use App\Models\SallaAppInstallation;
use Illuminate\Support\Str;
use Carbon\Carbon;

class SallaWebhookAndInstallationSeeder extends Seeder
{
    public function run()
    {
        $payload = [
            'event' => 'app.uninstalled',
            'merchant' => 1841950944,
            'created_at' => '2026-02-22 14:49:56',
            'data' => [
                'id' => 455867391,
                'app_name' => 'ChatBerry-R',
                'app_description' => "a cloud-based SaaS platform that enables businesses to run official, automated, and scalable WhatsApp marketing and customer engagement campaigns.\nIt is powered by the WhatsApp Business API, with bui",
                'app_type' => 'public',
                'installation_date' => '2026-02-22 14:45:34',
                'uninstallation_date' => '2026-02-22 14:49:56',
                'refunded' => false,
                'store_type' => 'demo',
            ],
        ];

        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $raw);

        // Create or update a SallaAppInstallation so the admin list shows the merchant
        SallaAppInstallation::updateOrCreate(
            [
                'merchant_id' => (string) $payload['merchant'],
                'salla_installation_id' => (int) $payload['data']['id'],
            ],
            [
                'app_name' => $payload['data']['app_name'],
                'app_description' => $payload['data']['app_description'],
                'app_type' => $payload['data']['app_type'],
                'store_type' => $payload['data']['store_type'],
                'installation_date' => Carbon::parse($payload['data']['installation_date']),
                'webhook_created_at' => Carbon::parse($payload['created_at']),
                'last_seen_at' => now(),
                'payload' => $payload,
                'uninstalled' => false,
            ]
        );

        // Insert delivery (pending) so it shows as pending uninstall
        SallaWebhookDelivery::firstOrCreate(
            ['payload_hash' => $hash],
            [
                'uuid' => (string) Str::uuid(),
                'merchant_id' => (string) $payload['merchant'],
                'salla_store_id' => null,
                'organization_id' => null,
                'event' => $payload['event'],
                'payload' => $payload,
                'headers' => [],
                'payload_hash' => $hash,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => Carbon::parse($payload['created_at']),
                'updated_at' => now(),
            ]
        );
    }
}
