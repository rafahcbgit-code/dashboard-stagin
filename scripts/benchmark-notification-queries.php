<?php

// Synthetic local benchmark. Only connection-local temporary tables are written.
require dirname(__DIR__).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$host = $_ENV['DB_HOST'] ?? '';
if (! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
    fwrite(STDERR, "This benchmark requires a loopback database connection.\n");
    exit(1);
}

try {
    $pdo = new PDO('mysql:host='.$host.';port='.($_ENV['DB_PORT'] ?? 3306)
        .';dbname='.$_ENV['DB_DATABASE'].';charset=utf8mb4',
        $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo 'Database: '.$pdo->query('SELECT VERSION()')->fetchColumn()."\n";
    $pdo->exec('CREATE TEMPORARY TABLE notification_perf_logs (
        id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED, organization_id BIGINT UNSIGNED,
        notification_type VARCHAR(100), clicked TINYINT, sent_at DATETIME,
        INDEX existing_inbox (user_id, organization_id, notification_type, sent_at, id),
        INDEX existing_unread (user_id, organization_id, notification_type, clicked)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TEMPORARY TABLE notification_perf_subscriptions (
        id BIGINT UNSIGNED PRIMARY KEY, organization_id BIGINT UNSIGNED, status VARCHAR(20),
        valid_until DATETIME, expiry_notification_processed_at DATETIME,
        INDEX existing_org (organization_id, id), INDEX existing_due (status, valid_until, id)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TEMPORARY TABLE notification_perf_events (
        id BIGINT UNSIGNED PRIMARY KEY, organization_id BIGINT UNSIGNED,
        type_key VARCHAR(100), event_key CHAR(64),
        UNIQUE INDEX existing_identity (organization_id, type_key, event_key)
    ) ENGINE=InnoDB');

    // 100,000 inbox rows, including 50,000 rows for one busy owner.
    // 100,000 subscription rows: 100 historical records for each of 1,000 tenants.
    for ($offset = 0; $offset < 100000; $offset += 500) {
        $logs = $subscriptions = $events = [];
        for ($id = $offset + 1; $id <= $offset + 500; $id++) {
            $owner = $id <= 50000 ? 1 : 2 + ($id % 1000);
            $sent = gmdate('Y-m-d H:i:s', strtotime('2026-01-01 UTC') + $id);
            $logs[] = [$id, $owner, $owner, 'type_'.($id % 20), $id <= 49000 ? 1 : 0, $sent];
            $subscriptions[] = [$id, intdiv($id - 1, 100) + 1, 'active', '2026-09-01 00:00:00',
                $id % 100 === 0 && $id % 1000 !== 0 ? '2026-09-02 00:00:00' : null];
            $org = intdiv($id - 1, 100) + 1;
            $events[] = [$id, $org, 'reminder_'.($org % 2), hash('sha256', (string) $id)];
        }
        insertRows($pdo, 'notification_perf_logs', $logs);
        insertRows($pdo, 'notification_perf_subscriptions', $subscriptions);
        insertRows($pdo, 'notification_perf_events', $events);
    }
    $types = implode(',', array_map(fn ($id) => "'type_$id'", range(0, 19)));
    $scope = "user_id=1 AND organization_id=1 AND notification_type IN ($types)";
    $inbox = "SELECT id,sent_at FROM notification_perf_logs WHERE $scope ORDER BY sent_at DESC,id DESC LIMIT 20";
    $popup = "SELECT id FROM notification_perf_logs WHERE $scope AND clicked=0 ORDER BY sent_at,id LIMIT 1";
    measure($pdo, 'inbox_before', $inbox);
    measure($pdo, 'popup_before', $popup);
    $pdo->exec('ALTER TABLE notification_perf_logs ADD INDEX ordered_inbox (user_id, organization_id, sent_at, id, notification_type),
        ADD INDEX ordered_popup (user_id, organization_id, clicked, sent_at, id, notification_type)');
    measure($pdo, 'inbox_after', $inbox);
    measure($pdo, 'popup_after', $popup);

    $ids = implode(',', range(1, 100));
    measure($pdo, 'subscriptions_before', "SELECT * FROM notification_perf_subscriptions WHERE organization_id IN ($ids) ORDER BY id DESC");
    measure($pdo, 'subscriptions_after', "SELECT * FROM notification_perf_subscriptions WHERE id IN
        (SELECT MAX(id) FROM notification_perf_subscriptions WHERE organization_id IN ($ids) GROUP BY organization_id)");

    $hashes = $identities = [];
    foreach (range(1, 200) as $org) {
        $keys = [hash('sha256', (string) ($org * 100 - 1)), hash('sha256', (string) ($org * 100))];
        $hashes = array_merge($hashes, $keys);
        $identities[] = "(organization_id=$org AND type_key='reminder_".($org % 2)."' AND event_key IN ('".implode("','", $keys)."'))";
    }
    measure($pdo, 'reminder_lookup_broad_in', "SELECT event_key FROM notification_perf_events WHERE organization_id IN ("
        .implode(',', range(1, 200)).") AND type_key IN ('reminder_0','reminder_1') AND event_key IN ('".implode("','", $hashes)."')");
    measure($pdo, 'reminder_lookup_exact_identities', 'SELECT event_key FROM notification_perf_events WHERE '.implode(' OR ', $identities));

    $due = "status='active' AND valid_until BETWEEN '2026-08-01' AND '2026-09-29'
        AND NOT EXISTS (SELECT 1 FROM notification_perf_subscriptions newer
            WHERE newer.organization_id = notification_perf_subscriptions.organization_id
            AND newer.id > notification_perf_subscriptions.id)";
    measure($pdo, 'expiry_candidates_before', "SELECT id FROM notification_perf_subscriptions WHERE $due ORDER BY id LIMIT 200");
    measure($pdo, 'expiry_candidates_after', "SELECT id FROM notification_perf_subscriptions WHERE $due
        AND (valid_until > '2026-09-28' OR expiry_notification_processed_at IS NULL OR expiry_notification_processed_at < valid_until)
        ORDER BY id LIMIT 200");
    $latest = "status='active' AND valid_until BETWEEN '2026-08-01' AND '2026-09-29'
        AND id = (SELECT newer.id FROM notification_perf_subscriptions newer
            WHERE newer.organization_id = notification_perf_subscriptions.organization_id ORDER BY newer.id DESC LIMIT 1)";
    measure($pdo, 'expiry_candidates_latest_lookup', "SELECT id FROM notification_perf_subscriptions WHERE $latest
        AND (valid_until > '2026-09-28' OR expiry_notification_processed_at IS NULL OR expiry_notification_processed_at < valid_until)
        ORDER BY id LIMIT 200");
} catch (Throwable $exception) {
    // Never print credentials, connection strings, or application data.
    fwrite(STDERR, 'Benchmark failed ('.get_class($exception).', code '.$exception->getCode().").\n");
    exit(1);
}

function insertRows(PDO $pdo, string $table, array $rows): void
{
    $placeholders = implode(',', array_fill(0, count($rows), '('.implode(',', array_fill(0, count($rows[0]), '?')).')'));
    $pdo->prepare("INSERT INTO $table VALUES $placeholders")->execute(array_merge(...$rows));
}

function measure(PDO $pdo, string $name, string $sql): void
{
    $times = [];
    for ($i = 0; $i < 4; $i++) {
        $start = hrtime(true);
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if ($i > 0) {
            $times[] = (hrtime(true) - $start) / 1000000;
        }
    }
    sort($times);
    $plan = $pdo->query('EXPLAIN '.$sql)->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['query' => $name, 'median_ms' => round($times[1], 3), 'returned_rows' => count($rows),
        'plan' => array_map(fn ($row) => array_intersect_key($row, array_flip(['table', 'type', 'key', 'rows', 'Extra'])), $plan)], JSON_UNESCAPED_SLASHES)."\n";
}
