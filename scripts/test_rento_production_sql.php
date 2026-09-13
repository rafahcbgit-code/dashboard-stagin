<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

if (!in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Run this verification only on the local database.');
}
$pdo = DB::connection()->getPdo();
$organization = DB::table('organizations')->where('id', 285)->first();
$sourceFlows = DB::table('flows')->where('organization_id', 285)->get();
$sourceTriggers = DB::table('trigger_groups')->where('organization_id', 285)->get();
$file = file_get_contents(__DIR__ . '/../exports/rento-production-285-safe.sql');
if (!preg_match('/CREATE PROCEDURE `([^`]+)`\(\)\RBEGIN\R[\s\S]+?\REND\$\$/', $file, $match)) {
    throw new RuntimeException('Could not read import routine.');
}
$originalName = $match[1];
$create = substr($match[0], 0, -2);
$testName = 'test_rento_export_' . bin2hex(random_bytes(6));
$create = str_replace('`' . $originalName . '`', '`' . $testName . '`', $create);
$routineExists = false;
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$call = static function () use ($pdo, $testName): array {
    $statement = $pdo->query('CALL `' . $testName . '`()');
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    while ($statement->nextRowset()) {
        $statement->fetchAll();
    }
    $statement->closeCursor();
    return $rows;
};
$expectError = static function (string $needle) use ($call, $assert): void {
    try {
        $call();
    } catch (PDOException $e) {
        $assert(str_contains($e->getMessage(), $needle), 'Unexpected failure: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Expected import to fail: ' . $needle);
};

try {
    // These session-local tables shadow production-shaped tables; persistent rows are never written.
    foreach (['organizations', 'flows', 'trigger_groups'] as $table) {
        $prototype = 'rento_test_' . $table;
        $pdo->exec('CREATE TEMPORARY TABLE `' . $prototype . '` LIKE `' . $table . '`');
        $definition = $pdo->query('SHOW CREATE TABLE `' . $prototype . '`')->fetch(PDO::FETCH_NUM)[1];
        $pdo->exec(preg_replace('/^CREATE (?:TEMPORARY )?TABLE `[^`]+`/', 'CREATE TEMPORARY TABLE `' . $table . '`', $definition));
        $pdo->exec('DROP TEMPORARY TABLE `' . $prototype . '`');
    }
    DB::table('organizations')->insert((array) $organization);
    foreach (['flows' => $sourceFlows, 'trigger_groups' => $sourceTriggers] as $table => $rows) {
        foreach ($rows as $row) {
            $copy = (array) $row;
            $copy['organization_id'] = 999999;
            DB::table($table)->insert($copy);
        }
    }
    $pdo->exec($create);
    $routineExists = true;
    $result = $call();
    $assert((int) $result[0]['imported_flows'] === 71 && (int) $result[0]['imported_triggers'] === 113, 'Wrong import counts.');
    $newFlows = DB::table('flows')->where('organization_id', 285)->get()->keyBy('id');
    $newTriggers = DB::table('trigger_groups')->where('organization_id', 285)->get()->keyBy('uuid');
    $sourceUuids = array_merge($sourceFlows->pluck('uuid')->all(), $sourceTriggers->pluck('uuid')->all());
    $portals = 0;
    foreach ($newFlows as $flow) {
        $assert(!in_array($flow->uuid, $sourceUuids, true), 'A source flow UUID was reused.');
        $metadata = json_decode($flow->metadata, true, 512, JSON_THROW_ON_ERROR);
        foreach ($metadata['nodes'] as $node) {
            $assert($node['data']['uuid'] === $flow->uuid, 'Incorrect node owner UUID.');
            if ($node['type'] !== 'portal') {
                continue;
            }
            $fields = $node['data']['metadata']['fields'];
            $assert(is_int($fields['target_flow_id']), 'Portal ID must remain a JSON number.');
            $target = $newFlows[$fields['target_flow_id']] ?? null;
            $assert($target && $target->uuid === $fields['target_flow_uuid'], 'Incorrect portal flow mapping.');
            $trigger = $newTriggers[$fields['trigger_uuid']] ?? null;
            $assert($trigger && (int) $trigger->flow_id === (int) $target->id, 'Incorrect portal trigger mapping.');
            $portals++;
        }
    }
    foreach ($newTriggers as $trigger) {
        $assert(isset($newFlows[$trigger->flow_id]), 'Trigger points outside imported bundle.');
        $assert(!in_array($trigger->uuid, $sourceUuids, true), 'A source trigger UUID was reused.');
    }
    $assert($portals === 112, 'Incorrect portal count.');
    $expectError('already exists');
    $assert(DB::table('flows')->where('organization_id', 285)->count() === 71, 'Repeat import changed rows.');
    $assert(DB::table('flows')->where('organization_id', 999999)->count() === count($sourceFlows), 'Unrelated organization changed.');

    DB::table('flows')->where('organization_id', 285)->delete();
    DB::table('trigger_groups')->where('organization_id', 285)->delete();
    $pdo->exec('DROP PROCEDURE `' . $testName . '`');
    $routineExists = false;
    $failing = str_replace('UPDATE flows SET metadata = v_metadata', "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forced rollback verification';\nUPDATE flows SET metadata = v_metadata", $create);
    $pdo->exec($failing);
    $routineExists = true;
    $expectError('Forced rollback verification');
    $assert(DB::table('flows')->where('organization_id', 285)->count() === 0, 'Failed import did not roll back flows.');
    $assert(DB::table('trigger_groups')->where('organization_id', 285)->count() === 0, 'Failed import did not roll back triggers.');
    $pdo->exec('DROP PROCEDURE `' . $testName . '`');
    $routineExists = false;
    $pdo->exec($create);
    $routineExists = true;

    $conflict = (array) $sourceTriggers->first(fn ($row) => in_array('000', json_decode($row->keywords, true), true));
    unset($conflict['id']);
    $conflict['uuid'] = (string) Illuminate\Support\Str::uuid();
    $conflict['organization_id'] = 285;
    $conflict['metadata'] = '{}';
    DB::table('trigger_groups')->insert($conflict);
    $expectError('keyword conflicts');
    $assert(DB::table('flows')->where('organization_id', 285)->count() === 0, 'Keyword conflict inserted flows.');
    DB::table('trigger_groups')->where('organization_id', 285)->delete();
    DB::table('organizations')->where('id', 285)->delete();
    $expectError('does not exist');
    echo json_encode(['passed' => true, 'flows' => 71, 'triggers' => 113, 'verified_portals' => $portals, 'checks' => ['fresh UUIDs', 'new numeric IDs', 'portal/trigger mapping', 'duplicate import rejected', 'rollback after partial inserts', 'existing 000 rejected', 'missing organization rejected', 'other organization preserved'], 'persistent_rows_modified' => 0], JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    if ($routineExists) {
        $pdo->exec('DROP PROCEDURE `' . $testName . '`');
    }
    // Session termination automatically discards all temporary tables.
}
