<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\WhatsappService;
use App\Support\WhatsappTemplateSyncStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SyncWhatsappTemplatesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $organizationId,
        public string $wabaId
    ) {}

    public function uniqueId(): string
    {
        return $this->organizationId . ':' . hash('sha256', $this->wabaId);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('whatsapp-template-sync-' . $this->uniqueId()))
                ->releaseAfter(20)
                ->expireAfter(1800),
        ];
    }

    public function handle(): void
    {
        $organization = Organization::find($this->organizationId);
        $metadata = json_decode($organization?->metadata ?: '{}', true) ?: [];
        $whatsapp = data_get($metadata, 'whatsapp', []);

        if (! $organization
            || (string) ($whatsapp['waba_id'] ?? '') !== $this->wabaId
            || empty($whatsapp['access_token'])) {
            WhatsappTemplateSyncStatus::update($this->organizationId, $this->wabaId, 'superseded');

            return;
        }

        WhatsappTemplateSyncStatus::update($this->organizationId, $this->wabaId, 'running');

        $service = new WhatsappService(
            $whatsapp['access_token'],
            config('graph.api_version'),
            $whatsapp['app_id'] ?? null,
            $whatsapp['phone_number_id'] ?? null,
            $this->wabaId,
            $this->organizationId
        );

        $result = $service->syncTemplates();
        if (($result->success ?? true) === false) {
            throw new RuntimeException($result->message ?? data_get($result, 'data.error.message') ?? 'WhatsApp template synchronization failed.');
        }

        WhatsappTemplateSyncStatus::update($this->organizationId, $this->wabaId, 'complete', [
            'counts' => (array) ($result->counts ?? []),
            'duration_ms' => $result->duration_ms ?? null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        WhatsappTemplateSyncStatus::update($this->organizationId, $this->wabaId, 'failed', [
            'message' => __('Template synchronization failed. Please try again.'),
        ]);

        Log::error('WhatsApp template synchronization job failed.', [
            'organization_id' => $this->organizationId,
            'waba_id' => $this->wabaId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
