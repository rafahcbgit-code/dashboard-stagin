<?php

namespace App\Jobs;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\EmbeddedSignup\Services\MetaService;
use RuntimeException;
use Throwable;

class OverrideWhatsappWabaCallbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $timeout = 30;

    public function __construct(public int $organizationId) {}

    public function handle(): void
    {
        $organization = Organization::find($this->organizationId);
        $metadata = json_decode($organization?->metadata ?: '{}', true) ?: [];
        $whatsapp = data_get($metadata, 'whatsapp', []);

        if (! $organization
            || (int) ($whatsapp['is_embedded_signup'] ?? 0) !== 1
            || empty($whatsapp['access_token'])
            || empty($whatsapp['waba_id'])
            || ! class_exists(MetaService::class)) {
            return;
        }

        $result = app(MetaService::class)->overrideWabaCallbackUrl($this->organizationId);

        if (! ($result->success ?? false)) {
            throw new RuntimeException('Unable to refresh the WhatsApp webhook callback URL.');
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('WhatsApp webhook callback refresh job failed.', [
            'organization_id' => $this->organizationId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
