<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$sourceId = 285;
$targetId = 285;
$bundle = 'rento-car-rental-v1';
$output = __DIR__ . '/../exports/rento-production-285-safe.sql';
$organization = DB::table('organizations')->where('id', $sourceId)->whereNull('deleted_at')->first();
if (!$organization) {
    throw new RuntimeException('Source organization 285 is missing.');
}
[$flows, $triggers] = DB::transaction(function () use ($sourceId, $bundle) {
    $query = static fn (string $table) => DB::table($table)
        ->where('organization_id', $sourceId)->whereNull('deleted_at')
        ->whereRaw("BINARY JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.import_bundle_id')) = BINARY ?", [$bundle])
        ->orderBy('id')->get();
    return [$query('flows'), $query('trigger_groups')];
});
if ($flows->isEmpty() || $triggers->isEmpty()) {
    throw new RuntimeException('The source bundle is empty.');
}

$sql = static function ($value): string {
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return $value === '' ? "''" : 'CONVERT(0x' . bin2hex((string) $value) . ' USING utf8mb4)';
};
$encode = static fn ($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$uuidVariables = [];
$flowById = [];
foreach ($flows as $flow) {
    $uuidVariables[$flow->uuid] = 'v_flow_uuid_' . (int) $flow->id;
    $flowById[(int) $flow->id] = $flow;
}
foreach ($triggers as $trigger) {
    if (!isset($flowById[(int) $trigger->flow_id]) || isset($uuidVariables[$trigger->uuid])) {
        throw new RuntimeException('Invalid source trigger reference or duplicate UUID.');
    }
    $uuidVariables[$trigger->uuid] = 'v_trigger_uuid_' . (int) $trigger->id;
}

// Rewrite JSON values through JSON_SET, including IDs as numbers, not text replacements.
$uuidPaths = function ($value, string $path = '$') use (&$uuidPaths, $uuidVariables): array {
    $paths = [];
    if (is_array($value)) {
        foreach ($value as $key => $child) {
            $childPath = is_int($key) ? $path . '[' . $key . ']' : $path . '.' . json_encode((string) $key, JSON_THROW_ON_ERROR);
            $paths += $uuidPaths($child, $childPath);
        }
    } elseif (is_string($value) && isset($uuidVariables[$value])) {
        $paths[$path] = $uuidVariables[$value];
    }
    return $paths;
};

$procedure = 'import_rento_285_' . bin2hex(random_bytes(6));
$lockName = 'chatberry-import-org-285';
$lines = [
    '-- Rento Car Rental: source organization 285 -> production organization 285.',
    '-- Import the WHOLE file into the selected application database, in one connection.',
    '-- Requires MySQL 5.7+ / MariaDB with JSON functions, InnoDB, and routine privileges.',
    '-- Back up flows and trigger_groups first. No existing rows are updated or deleted.',
    '-- UUIDs are generated and checked on the destination; numeric IDs use AUTO_INCREMENT.',
    '-- Duplicate bundle / matching enabled exact keywords abort the whole import.',
    '-- This is an unchanged content export, not a functional audit of the chatbot.',
    'SET NAMES utf8mb4;',
    'DELIMITER $$',
    'CREATE PROCEDURE `' . $procedure . '`()',
    'BEGIN',
    'DECLARE v_org BIGINT UNSIGNED DEFAULT ' . $targetId . ';',
    'DECLARE v_locked INT DEFAULT 0;',
    'DECLARE v_org_found BIGINT UNSIGNED DEFAULT NULL;',
    'DECLARE v_metadata LONGTEXT CHARACTER SET utf8mb4;',
];
foreach ($uuidVariables as $variable) {
    $lines[] = 'DECLARE ' . $variable . ' CHAR(36) CHARACTER SET utf8mb4;';
}
foreach ($flows as $flow) {
    $lines[] = 'DECLARE v_flow_id_' . (int) $flow->id . ' BIGINT UNSIGNED;';
}
$lines = array_merge($lines, [
    'DECLARE EXIT HANDLER FOR SQLEXCEPTION',
    'BEGIN',
    'ROLLBACK;',
    'IF v_locked = 1 THEN DO RELEASE_LOCK(' . $sql($lockName) . '); END IF;',
    'RESIGNAL;',
    'END;',
    'SELECT GET_LOCK(' . $sql($lockName) . ', 15) INTO v_locked;',
    "IF COALESCE(v_locked, 0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Another import is running; retry later.'; END IF;",
    "IF (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('organizations', 'flows', 'trigger_groups') AND engine = 'InnoDB') <> 3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'All three target tables must exist and use InnoDB.'; END IF;",
    'START TRANSACTION;',
    'SELECT id INTO v_org_found FROM organizations WHERE id = v_org AND deleted_at IS NULL FOR UPDATE;',
    "IF v_org_found IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Target organization 285 does not exist.'; END IF;",
]);
foreach (['flows', 'trigger_groups'] as $table) {
    $lines[] = 'IF EXISTS (SELECT 1 FROM ' . $table . " WHERE organization_id = v_org AND BINARY JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.import_bundle_id')) = BINARY " . $sql($bundle) . ") THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This Rento bundle already exists. Import cancelled; nothing changed.'; END IF;";
}
$keywords = [];
foreach ($triggers as $trigger) {
    foreach (json_decode($trigger->keywords, true, 512, JSON_THROW_ON_ERROR) as $keyword) {
        $keywords[$keyword] = $keyword;
    }
}
foreach ($keywords as $keyword) {
    $lines[] = "IF EXISTS (SELECT 1 FROM trigger_groups WHERE organization_id = v_org AND deleted_at IS NULL AND is_enabled = 1 AND type = 'new_message' AND match_mode = 'exact' AND JSON_CONTAINS(keywords, " . $sql($encode($keyword)) . ")) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An enabled exact keyword conflicts with this import. Review existing triggers.'; END IF;";
}
$allocate = static function (string $variable) use (&$lines): void {
    $lines[] = 'SET ' . $variable . ' = UUID();';
    $lines[] = 'WHILE EXISTS (SELECT 1 FROM flows WHERE BINARY uuid = BINARY ' . $variable . ') OR EXISTS (SELECT 1 FROM trigger_groups WHERE BINARY uuid = BINARY ' . $variable . ') DO';
    $lines[] = 'SET ' . $variable . ' = UUID();';
    $lines[] = 'END WHILE;';
};
foreach ($flows as $flow) {
    $variable = $uuidVariables[$flow->uuid];
    $allocate($variable);
    $lines[] = 'INSERT INTO flows (uuid, organization_id, name, description, metadata, status, is_builtin, created_at, updated_at) VALUES ('
        . implode(', ', [$variable, 'v_org', $sql($flow->name), $sql($flow->description), "'{}'", $sql($flow->status), (int) $flow->is_builtin, 'NOW()', 'NOW()']) . ');';
    $lines[] = 'SET v_flow_id_' . (int) $flow->id . ' = LAST_INSERT_ID();';
}
foreach ($triggers as $trigger) {
    $variable = $uuidVariables[$trigger->uuid];
    $allocate($variable);
    $lines[] = 'INSERT INTO trigger_groups (uuid, organization_id, name, type, keywords, match_mode, ai_confidence, flow_id, priority, is_system, is_enabled, rate_limit_type, metadata, created_at, updated_at) VALUES ('
        . implode(', ', [$variable, 'v_org', $sql($trigger->name), $sql($trigger->type), $sql($trigger->keywords), $sql($trigger->match_mode), $sql($trigger->ai_confidence), 'v_flow_id_' . (int) $trigger->flow_id, (int) $trigger->priority, (int) $trigger->is_system, (int) $trigger->is_enabled, $sql($trigger->rate_limit_type), "'{}'", 'NOW()', 'NOW()']) . ');';
}
$portalCount = 0;
foreach (['flows' => $flows, 'trigger_groups' => $triggers] as $table => $rows) {
    foreach ($rows as $row) {
        $metadata = json_decode($row->metadata, true, 512, JSON_THROW_ON_ERROR);
        $paths = $uuidPaths($metadata);
        if ($table === 'flows') {
            foreach ($metadata['nodes'] ?? [] as $index => $node) {
                if (($node['type'] ?? '') !== 'portal') {
                    continue;
                }
                $fields = $node['data']['metadata']['fields'];
                $target = $flowById[(int) ($fields['target_flow_id'] ?? 0)] ?? null;
                if (!$target || $target->uuid !== ($fields['target_flow_uuid'] ?? null)) {
                    throw new RuntimeException('Invalid portal reference in flow ' . $row->id);
                }
                if (!empty($fields['trigger_uuid']) && !isset($uuidVariables[$fields['trigger_uuid']])) {
                    throw new RuntimeException('Unknown portal trigger UUID in flow ' . $row->id);
                }
                $paths['$.nodes[' . $index . '].data.metadata.fields.target_flow_id'] = 'v_flow_id_' . (int) $target->id;
                $portalCount++;
            }
        }
        $lines[] = 'SET v_metadata = ' . $sql($encode($metadata)) . ';';
        foreach ($paths as $path => $variable) {
            $lines[] = 'SET v_metadata = JSON_SET(v_metadata, ' . $sql($path) . ', ' . $variable . ');';
        }
        $lines[] = 'UPDATE ' . $table . ' SET metadata = v_metadata WHERE organization_id = v_org AND BINARY uuid = BINARY ' . $uuidVariables[$row->uuid] . ';';
    }
}
$start = $triggers->first(fn ($trigger) => in_array('000', json_decode($trigger->keywords, true), true));
if (!$start) {
    throw new RuntimeException('Start trigger 000 is missing.');
}
$lines = array_merge($lines, [
    'COMMIT;',
    'DO RELEASE_LOCK(' . $sql($lockName) . ');',
    'SET v_locked = 0;',
    'SELECT v_org AS organization_id, ' . count($flows) . ' AS imported_flows, ' . count($triggers) . ' AS imported_triggers, v_flow_id_' . (int) $start->flow_id . " AS start_flow_id, '000' AS start_trigger;",
    'END$$',
    'DELIMITER ;',
    'CALL `' . $procedure . '`();',
    'DROP PROCEDURE `' . $procedure . '`;',
]);
file_put_contents($output, implode(PHP_EOL, $lines) . PHP_EOL);
echo $encode(['file' => realpath($output), 'source_name' => $organization->name, 'flows' => count($flows), 'triggers' => count($triggers), 'portals' => $portalCount, 'start_flow' => $flowById[(int) $start->flow_id]->name]) . PHP_EOL;
