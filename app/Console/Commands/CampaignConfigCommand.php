<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\OrganizationService;
use Illuminate\Console\Command;

class CampaignConfigCommand extends Command
{
    protected $signature = 'campaign:config
                            {organization : Organization ID or UUID}
                            {--chunk-size= : Messages per chunk job (1-200)}
                            {--concurrency= : Concurrent HTTP requests per chunk (1-50)}
                            {--delay= : Milliseconds between each API request (0-2000)}
                            {--phone-mps= : Max messages per second per WhatsApp phone number (default 20, max 80)}
                            {--batch-size= : Logs fetched per query batch (100-1000)}
                            {--show : Display current settings without changing}
                            {--reset : Remove org overrides, revert to env/defaults}';

    protected $description = 'Manage per-organization campaign sending settings';

    private const VALIDATION = [
        'chunk_size'     => ['min' => 1,   'max' => 200],
        'concurrency'    => ['min' => 1,   'max' => 50],
        'request_delay_ms' => ['min' => 0, 'max' => 2000],
        'phone_mps'      => ['min' => 1,   'max' => 80],
        'batch_size'     => ['min' => 100, 'max' => 1000],
    ];

    public function handle(): int
    {
        $org = $this->resolveOrganization();
        if (!$org) {
            return 1;
        }

        if ($this->option('show')) {
            return $this->showSettings($org);
        }

        if ($this->option('reset')) {
            return $this->resetSettings($org);
        }

        $hasChanges = $this->option('chunk-size') || $this->option('concurrency')
            || $this->option('delay') || $this->option('phone-mps') || $this->option('batch-size');

        if (!$hasChanges) {
            $this->warn('No settings provided. Use --show to view current settings, or pass --chunk-size, --concurrency, --delay, --phone-mps, --batch-size.');
            $this->line('Example: php artisan campaign:config 1 --phone-mps=40 --concurrency=2 --delay=100');

            return 1;
        }

        return $this->applySettings($org);
    }

    private function resolveOrganization(): ?Organization
    {
        $identifier = $this->argument('organization');

        $org = is_numeric($identifier)
            ? Organization::find((int) $identifier)
            : Organization::where('uuid', $identifier)->first();

        if (!$org) {
            $this->error("Organization not found: {$identifier}");

            return null;
        }

        return $org;
    }

    private function showSettings(Organization $org): int
    {
        $metadata = json_decode($org->metadata, true) ?? [];
        $campaigns = $metadata['campaigns'] ?? [];

        $this->info("Campaign settings for: {$org->name} (ID: {$org->id})");
        $this->newLine();

        $rows = [];
        foreach (['chunk_size', 'concurrency', 'request_delay_ms', 'phone_mps', 'batch_size'] as $key) {
            $orgVal = $campaigns[$key] ?? null;
            $defaultVal = $this->getConfigDefault($key);
            $effectiveVal = $orgVal ?? $defaultVal;

            $rows[] = [
                $key,
                $orgVal ?? '-',
                $defaultVal,
                $effectiveVal,
                $orgVal !== null ? 'org override' : 'env/default',
            ];
        }

        $this->table(['Setting', 'Org Override', 'Env/Default', 'Effective', 'Source'], $rows);
        $this->newLine();
        $this->line('Default campaign speed is 20 msg/s unless an organization override is configured.');
        $this->line('Meta rate limits: Default tier=80 msg/s, Upgraded=1000 msg/s, Coexistence=20 msg/s');

        return 0;
    }

    private function applySettings(Organization $org): int
    {
        $updates = [];
        $errors = [];

        $mapping = [
            'chunk-size'  => 'chunk_size',
            'concurrency' => 'concurrency',
            'delay'       => 'request_delay_ms',
            'phone-mps'   => 'phone_mps',
            'batch-size'  => 'batch_size',
        ];

        foreach ($mapping as $option => $configKey) {
            $value = $this->option($option);
            if ($value === null || $value === '') {
                continue;
            }

            $value = (int) $value;
            $rules = self::VALIDATION[$configKey];

            if ($value < $rules['min'] || $value > $rules['max']) {
                $errors[] = "{$configKey}: {$value} is out of range ({$rules['min']}-{$rules['max']})";
                continue;
            }

            $updates[$configKey] = $value;
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return 1;
        }

        if (empty($updates)) {
            $this->warn('No valid settings to apply.');

            return 1;
        }

        $this->previewChanges($org, $updates);

        if (!$this->confirm('Apply these changes?', true)) {
            $this->info('Cancelled.');

            return 0;
        }

        $metadata = json_decode($org->metadata, true) ?? [];
        if (!isset($metadata['campaigns'])) {
            $metadata['campaigns'] = [];
        }

        foreach ($updates as $key => $value) {
            $metadata['campaigns'][$key] = $value;
        }

        $org->metadata = json_encode($metadata);
        $org->save();

        OrganizationService::invalidateMetadataCache($org->id);

        $this->newLine();
        $this->info('Settings updated and cache invalidated.');
        $this->showSettings($org);

        return 0;
    }

    private function resetSettings(Organization $org): int
    {
        $metadata = json_decode($org->metadata, true) ?? [];
        $campaigns = $metadata['campaigns'] ?? [];
        $overrideKeys = ['chunk_size', 'concurrency', 'request_delay_ms', 'phone_mps', 'batch_size'];
        $found = false;

        foreach ($overrideKeys as $key) {
            if (isset($campaigns[$key])) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $this->info('No org overrides found. Already using env/defaults.');

            return 0;
        }

        if (!$this->confirm("Remove all campaign overrides for {$org->name}?", true)) {
            $this->info('Cancelled.');

            return 0;
        }

        foreach ($overrideKeys as $key) {
            unset($metadata['campaigns'][$key]);
        }

        if (empty($metadata['campaigns'])) {
            unset($metadata['campaigns']);
        }

        $org->metadata = json_encode($metadata);
        $org->save();

        OrganizationService::invalidateMetadataCache($org->id);

        $this->info('Org overrides removed. Reverted to env/defaults.');
        $this->showSettings($org);

        return 0;
    }

    private function previewChanges(Organization $org, array $updates): void
    {
        $metadata = json_decode($org->metadata, true) ?? [];
        $campaigns = $metadata['campaigns'] ?? [];

        $this->info("Changes for: {$org->name} (ID: {$org->id})");
        $this->newLine();

        $rows = [];
        foreach ($updates as $key => $newVal) {
            $currentVal = $campaigns[$key] ?? $this->getConfigDefault($key);
            $rows[] = [
                $key,
                $currentVal,
                $newVal,
            ];
        }

        $this->table(['Setting', 'Current', 'New'], $rows);
    }

    private function getConfigDefault(string $key)
    {
        return match ($key) {
            'chunk_size'       => config('campaigns.chunk_size', 50),
            'concurrency'      => config('campaigns.concurrency', 10),
            'request_delay_ms' => config('campaigns.request_delay_ms', 100),
            'phone_mps'        => config('campaigns.throttle.default_phone_mps', 20),
            'batch_size'       => config('campaigns.batch_size', 500),
            default            => '-',
        };
    }
}
