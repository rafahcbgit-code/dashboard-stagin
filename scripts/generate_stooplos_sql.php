<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$sourceOrganizationId = (int) ($argv[1] ?? 451);
$targetOrganizationId = (int) ($argv[2] ?? 679);
$bundleId = (string) ($argv[4] ?? 'stooplos-chatbot-v1');
$bundleSlug = preg_replace('/[^A-Za-z0-9]+/', '_', strtolower($bundleId)) ?: 'bundle';
$outputPath = $argv[3] ?? (__DIR__ . '/../exports/' . $bundleSlug . '-import-' . $targetOrganizationId . '.sql');

$sourceOrganization = DB::table('organizations')->where('id', $sourceOrganizationId)->first();
if (!$sourceOrganization) {
    throw new RuntimeException("Source organization {$sourceOrganizationId} was not found.");
}

$flows = DB::table('flows')
    ->where('organization_id', $sourceOrganizationId)
    ->where('metadata', 'like', '%"import_bundle_id":"' . $bundleId . '"%')
    ->orderBy('id')
    ->get();

if ($flows->isEmpty()) {
    throw new RuntimeException("No {$bundleId} flows were found for source organization {$sourceOrganizationId}.");
}

$flowUuidMap = [];
$flowIdMap = [];
foreach ($flows as $flow) {
    $flowUuidMap[(string) $flow->uuid] = (string) Str::uuid();
    $flowIdMap[(int) $flow->id] = $flowUuidMap[(string) $flow->uuid];
}

$triggers = DB::table('trigger_groups')
    ->where('organization_id', $sourceOrganizationId)
    ->where('metadata', 'like', '%"import_bundle_id":"' . $bundleId . '"%')
    ->orderBy('id')
    ->get();

if ($triggers->isEmpty()) {
    throw new RuntimeException("No {$bundleId} trigger groups were found for source organization {$sourceOrganizationId}.");
}

$triggerUuidMap = [];
foreach ($triggers as $trigger) {
    $triggerUuidMap[(string) $trigger->uuid] = (string) Str::uuid();
}

$rewrite = static function ($value) use (&$rewrite, $flowUuidMap, $triggerUuidMap) {
    if (is_array($value)) {
        foreach ($value as $key => $child) {
            $value[$key] = $rewrite($child);
        }
        return $value;
    }
    if (is_string($value)) {
        return $flowUuidMap[$value] ?? $triggerUuidMap[$value] ?? $value;
    }
    return $value;
};

$sqlValue = static function ($value): string {
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    $text = (string) $value;
    return 'CONVERT(0x' . bin2hex($text) . ' USING utf8mb4)';
};

$lines = [
    '-- Chatbot import generated from organization ' . $sourceOrganizationId,
    '-- Target organization: ' . $targetOrganizationId,
    '-- Fresh UUIDs are embedded below; auto-increment IDs are resolved after flow inserts.',
    'SET NAMES utf8mb4;',
    'SET @target_organization_id = ' . $targetOrganizationId . ';',
    'SET @bundle_id = ' . $sqlValue($bundleId) . ';',
    '',
    'SELECT id, name FROM organizations WHERE id = @target_organization_id;',
    'SELECT COUNT(*) AS existing_bundle_rows FROM flows WHERE organization_id = @target_organization_id AND BINARY JSON_UNQUOTE(JSON_EXTRACT(metadata, \'$.import_bundle_id\')) = BINARY @bundle_id;',
    '',
    'START TRANSACTION;',
];

$portalReferences = [];
foreach ($flows as $flow) {
    $metadata = json_decode((string) $flow->metadata, true, 512, JSON_THROW_ON_ERROR);
    $metadata = $rewrite($metadata);

    foreach (($metadata['nodes'] ?? []) as $node) {
        if (($node['type'] ?? null) !== 'portal') {
            continue;
        }
        $fields = $node['data']['metadata']['fields'] ?? [];
        $oldTargetId = isset($fields['target_flow_id']) ? (int) $fields['target_flow_id'] : 0;
        if (!isset($flowIdMap[$oldTargetId])) {
            throw new RuntimeException("Portal in flow {$flow->id} points to unknown flow ID {$oldTargetId}.");
        }
        $marker = '__IMPORT_TARGET_ID_' . $oldTargetId . '__';
        $metadata['nodes'] = $metadata['nodes'];
        $portalReferences[(int) $flow->id][ $oldTargetId ] = $marker;
    }

    // Replace numeric portal IDs with temporary JSON markers. They are converted to
    // the target server's auto-increment IDs after all flows have been inserted.
    foreach ($metadata['nodes'] ?? [] as $nodeIndex => $node) {
        if (($node['type'] ?? null) !== 'portal') {
            continue;
        }
        $oldTargetId = (int) ($node['data']['metadata']['fields']['target_flow_id'] ?? 0);
        $metadata['nodes'][$nodeIndex]['data']['metadata']['fields']['target_flow_id'] = '__IMPORT_TARGET_ID_' . $oldTargetId . '__';
    }

    $metadata['import_bundle_id'] = $bundleId;
    $metadata['import_source_organization_id'] = $sourceOrganizationId;
    $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $newUuid = $flowUuidMap[(string) $flow->uuid];

    $lines[] = 'INSERT INTO flows (uuid, organization_id, name, description, metadata, status, is_builtin, created_at, updated_at) VALUES ('
        . implode(', ', [
            $sqlValue($newUuid),
            '@target_organization_id',
            $sqlValue($flow->name),
            $sqlValue($flow->description),
            $sqlValue($metadataJson),
            $sqlValue($flow->status ?: 'active'),
            (int) $flow->is_builtin,
            'NOW()',
            'NOW()',
        ]) . ');';
}

$lines[] = '';
$lines[] = '-- Resolve every new flow UUID to its production auto-increment ID.';
foreach ($flows as $flow) {
    $newUuid = $flowUuidMap[(string) $flow->uuid];
    $lines[] = 'SET @import_flow_' . (int) $flow->id . ' = (SELECT id FROM flows WHERE BINARY uuid = BINARY ' . $sqlValue($newUuid) . ' AND organization_id = @target_organization_id LIMIT 1);';
}

$lines[] = '';
$lines[] = '-- Replace portal markers with the resolved production flow IDs (avoids MySQL #1093).';
foreach ($portalReferences as $sourceFlowId => $targets) {
    $sourceUuid = $flowUuidMap[(string) $flows->firstWhere('id', $sourceFlowId)->uuid];
    foreach ($targets as $oldTargetId => $marker) {
        $lines[] = 'UPDATE flows SET metadata = REPLACE(CONVERT(metadata USING utf8mb4) COLLATE utf8mb4_unicode_ci, '
            . $sqlValue('"target_flow_id":"' . $marker . '"') . ' COLLATE utf8mb4_unicode_ci, '
            . 'CONCAT(' . $sqlValue('"target_flow_id":') . ', @import_flow_' . $oldTargetId . ')) WHERE BINARY uuid = BINARY '
            . $sqlValue($sourceUuid) . ' AND organization_id = @target_organization_id;';
    }
}

$lines[] = '';
$lines[] = '-- Insert button triggers after all flow IDs are known.';
foreach ($triggers as $trigger) {
    $triggerMetadata = json_decode((string) $trigger->metadata, true) ?: [];
    $triggerMetadata['source'] = $triggerMetadata['source'] ?? 'imported_simulator';
    $triggerMetadata['import_bundle_id'] = $bundleId;
    $triggerMetadataJson = json_encode($triggerMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $flowVar = '@import_flow_' . (int) $trigger->flow_id;
    $lines[] = 'INSERT INTO trigger_groups (uuid, organization_id, name, type, keywords, match_mode, ai_confidence, flow_id, priority, is_system, is_enabled, rate_limit_type, metadata, created_at, updated_at) VALUES ('
        . implode(', ', [
            $sqlValue($triggerUuidMap[(string) $trigger->uuid]),
            '@target_organization_id',
            $sqlValue($trigger->name),
            $sqlValue($trigger->type ?: 'new_message'),
            $sqlValue($trigger->keywords),
            $sqlValue($trigger->match_mode ?: 'exact'),
            (int) ($trigger->ai_confidence ?? 75),
            $flowVar,
            (int) ($trigger->priority ?? 0),
            (int) ($trigger->is_system ?? 0),
            (int) ($trigger->is_enabled ?? 1),
            $sqlValue($trigger->rate_limit_type ?: 'none'),
            $sqlValue($triggerMetadataJson),
            'NOW()',
            'NOW()',
        ]) . ');';
}

$lines[] = '';
$lines[] = 'SELECT COUNT(*) AS imported_flows FROM flows WHERE organization_id = @target_organization_id AND BINARY JSON_UNQUOTE(JSON_EXTRACT(metadata, \'$.import_bundle_id\')) = BINARY @bundle_id;';
$lines[] = 'SELECT COUNT(*) AS imported_triggers FROM trigger_groups WHERE organization_id = @target_organization_id AND BINARY JSON_UNQUOTE(JSON_EXTRACT(metadata, \'$.import_bundle_id\')) = BINARY @bundle_id;';
$lines[] = 'COMMIT;';

$directory = dirname($outputPath);
if (!is_dir($directory)) {
    mkdir($directory, 0777, true);
}
file_put_contents($outputPath, implode(PHP_EOL, $lines) . PHP_EOL);

echo json_encode([
    'output' => realpath($outputPath) ?: $outputPath,
    'source_organization_id' => $sourceOrganizationId,
    'target_organization_id' => $targetOrganizationId,
    'flows' => $flows->count(),
    'triggers' => $triggers->count(),
    'portal_referencing_flows' => count($portalReferences),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
