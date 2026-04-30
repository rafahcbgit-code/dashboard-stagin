<?php

namespace App\Console\Commands;

use App\Models\TriggerGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\FlowBuilder\Models\Flow;

class MigrateOldFlows extends Command
{
    protected $signature = 'migrate:old-flows {file : Path to the flows JSON export file} {--force : Run without confirmation prompt}';

    protected $description = 'Migrate old flows to new format: clean metadata, create/update flows and trigger groups';

    private int $created = 0;
    private int $updated = 0;
    private int $triggersCreated = 0;
    private int $skipped = 0;
    private int $errors = 0;

    public function handle(): int
    {
        $file = $this->argument('file');

        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        $this->info('Reading flows JSON...');

        $json = json_decode(file_get_contents($file), true);

        if (!$json) {
            $this->error('Invalid JSON file');
            return self::FAILURE;
        }

        // phpMyAdmin JSON export: data is in item with type=table
        $rows = null;
        if (isset($json[0]['type']) && $json[0]['type'] === 'header') {
            // phpMyAdmin export format
            foreach ($json as $item) {
                if (($item['type'] ?? '') === 'table' && ($item['name'] ?? '') === 'flows') {
                    $rows = $item['data'] ?? [];
                    break;
                }
            }
        } elseif (is_array($json) && isset($json[0]['id'])) {
            // Plain array of flow objects
            $rows = $json;
        }

        if (!$rows) {
            $this->error('Could not find flow data in the JSON file');
            return self::FAILURE;
        }

        $total = count($rows);
        $this->info("Found {$total} flows in file");

        if (!$this->option('force') && !$this->confirm("Proceed with migration?")) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($rows as $row) {
            try {
                $this->migrateFlow($row);
            } catch (\Throwable $e) {
                $this->errors++;
                $this->warn("  Error on flow {$row['id']}: {$e->getMessage()}");
                Log::error('MigrateOldFlows: failed for flow', [
                    'flow_id' => $row['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Migration complete:");
        $this->info("  Flows created:    {$this->created}");
        $this->info("  Flows updated:    {$this->updated}");
        $this->info("  Triggers created: {$this->triggersCreated}");
        $this->info("  Skipped:          {$this->skipped}");
        $this->info("  Errors:           {$this->errors}");

        return self::SUCCESS;
    }

    private function migrateFlow(array $row): void
    {
        $flowId = (int) $row['id'];
        $orgId = (int) $row['organization_id'];
        $trigger = $row['trigger'] ?? null;
        $keywords = $row['keywords'] ?? null;
        $status = $row['status'] ?? 'inactive';
        $metadataRaw = $row['metadata'] ?? null;
        $deletedAt = $row['deleted_at'] ?? null;
        $name = $row['name'] ?? 'Untitled Flow';
        $description = $row['description'] ?? null;
        $uuid = $row['uuid'] ?? null;

        // Skip soft-deleted flows
        if ($deletedAt !== null) {
            $this->skipped++;
            return;
        }

        // Clean metadata — keep the full VueFlow format, only strip start node trigger info
        $cleanedMetadata = null;
        if ($metadataRaw) {
            $metadata = json_decode($metadataRaw, true);
            if ($metadata) {
                $this->cleanStartNodeTriggerInfo($metadata);
                $cleanedMetadata = json_encode($metadata, JSON_UNESCAPED_UNICODE);
            }
        }

        // Find existing flow by id + organization_id
        $existingFlow = Flow::where('id', $flowId)
            ->where('organization_id', $orgId)
            ->first();

        DB::transaction(function () use ($existingFlow, $flowId, $orgId, $uuid, $name, $description, $status, $cleanedMetadata) {
            if ($existingFlow) {
                // Update existing flow
                $existingFlow->name = $name;
                $existingFlow->description = $description;
                $existingFlow->status = $status;
                if ($cleanedMetadata) {
                    $existingFlow->metadata = $cleanedMetadata;
                }
                $existingFlow->save();
            } else {
                // Create new flow
                Flow::create([
                    'id' => $flowId,
                    'uuid' => $uuid,
                    'organization_id' => $orgId,
                    'name' => $name,
                    'description' => $description,
                    'metadata' => $cleanedMetadata,
                    'status' => $status,
                    'is_builtin' => false,
                ]);
            }
        });

        if ($existingFlow) {
            $this->updated++;
        } else {
            $this->created++;
        }

        // Fetch the flow (either existing or just created)
        $flow = Flow::find($flowId);

        if (!$flow) {
            $this->errors++;
            $this->warn("  Could not find/create flow {$flowId}");
            return;
        }

        // Create trigger group (only for active flows with a trigger type)
        if ($status === 'active' && $trigger !== null) {
            $this->createTriggerGroup($orgId, $flow->id, $name, $trigger, $keywords);
        }
    }

    /**
     * Remove old trigger/keywords info from start node's data.metadata.fields.
     * In the old format, the start node embedded: {type: "keywords", keywords: "hi"}
     * In the new format, triggers live in trigger_groups table, so this data is irrelevant.
     * Everything else in the metadata is kept as-is for VueFlow compatibility.
     */
    private function cleanStartNodeTriggerInfo(array &$metadata): void
    {
        foreach ($metadata['nodes'] as $i => $node) {
            if (($node['type'] ?? '') === 'start') {
                unset(
                    $metadata['nodes'][$i]['data']['metadata']['fields']['type'],
                    $metadata['nodes'][$i]['data']['metadata']['fields']['keywords']
                );
            }
        }
    }

    private function createTriggerGroup(int $orgId, int $flowId, string $flowName, ?string $trigger, ?string $keywords): void
    {
        // Idempotent: skip if a trigger already links to this flow
        $existing = TriggerGroup::where('organization_id', $orgId)
            ->where('flow_id', $flowId)
            ->first();

        if ($existing) {
            return;
        }

        switch ($trigger) {
            case 'new_contact':
                // Link the system Welcome trigger to this flow, or create one
                $welcomeTrigger = TriggerGroup::forOrganization($orgId)
                    ->where('type', 'new_contact')
                    ->first();

                if ($welcomeTrigger) {
                    // If it's the system trigger with no flow linked, link it
                    if ($welcomeTrigger->flow_id === null) {
                        $welcomeTrigger->update([
                            'flow_id' => $flowId,
                            'is_enabled' => true,
                        ]);
                        $this->triggersCreated++;
                    }
                    // If system trigger already has a different flow, create a new one
                    elseif ($welcomeTrigger->flow_id !== $flowId) {
                        $this->createNewContactTrigger($orgId, $flowId, $flowName);
                    }
                } else {
                    $this->createNewContactTrigger($orgId, $flowId, $flowName);
                }
                break;

            case 'keywords':
                $kwArray = $this->parseKeywords($keywords);
                if (!empty($kwArray)) {
                    $this->createKeywordTrigger($orgId, $flowId, $flowName, $kwArray);
                }
                break;

            case 'exact_match':
                $kwArray = $this->parseKeywords($keywords);
                if (!empty($kwArray)) {
                    $this->createKeywordTrigger($orgId, $flowId, $flowName, $kwArray);
                }
                break;

            default:
                // NULL or unknown trigger type — skip
                break;
        }
    }

    private function createNewContactTrigger(int $orgId, int $flowId, string $flowName): void
    {
        $priority = (TriggerGroup::forOrganization($orgId)->max('priority') ?? -1) + 1;

        TriggerGroup::create([
            'organization_id' => $orgId,
            'name' => $flowName,
            'type' => 'new_contact',
            'match_mode' => 'exact',
            'keywords' => null,
            'flow_id' => $flowId,
            'priority' => $priority,
            'is_system' => false,
            'is_enabled' => true,
            'rate_limit_type' => 'lifetime',
        ]);

        $this->triggersCreated++;
    }

    private function createKeywordTrigger(int $orgId, int $flowId, string $flowName, array $keywords): void
    {
        $priority = (TriggerGroup::forOrganization($orgId)->max('priority') ?? -1) + 1;

        TriggerGroup::create([
            'organization_id' => $orgId,
            'name' => $flowName,
            'type' => 'new_message',
            'match_mode' => 'exact',
            'keywords' => $keywords,
            'flow_id' => $flowId,
            'priority' => $priority,
            'is_system' => false,
            'is_enabled' => true,
            'rate_limit_type' => 'none',
        ]);

        $this->triggersCreated++;
    }

    private function parseKeywords(?string $keywords): array
    {
        if (!$keywords || trim($keywords) === '') {
            return [];
        }

        // Split by comma, trim each, remove empties
        $parts = array_map('trim', explode(',', $keywords));

        return array_values(array_filter($parts, fn($k) => $k !== ''));
    }
}