<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/core.php';
require dirname(__DIR__) . '/src/admin.php';
$count = 0;
function check(bool $condition, string $name): void {
    global $count;
    if (!$condition) { throw new RuntimeException($name); }
    $count++;
}
function rejects(callable $call, int $status): void {
    try { $call(); } catch (HttpError $error) { check($error->status === $status, 'Wrong status'); return; }
    throw new RuntimeException('Expected rejection');
}
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys=ON'); migrate_db($db);
$screens = [['title' => 'Power', 'rows' => [['label' => 'PV', 'value' => '5 kW']]]];
$app = ['id' => 'energy', 'title' => 'Energy', 'enabled' => true, 'ttl' => 900, 'screens' => $screens, 'version' => 0];
$created = save_app($db, $app);
check(strlen($created['token']) === 64, 'Generated publisher token');
check($db->query('SELECT token_hash FROM apps')->fetchColumn() !== $created['token'], 'Token is hashed');
rejects(fn() => save_app($db, $app), 409);
rejects(fn() => screens_value([]), 422);
rejects(fn() => screens_value([['title' => 'Wrong', 'rows' => [], 'script' => 'evil']]), 422);
rejects(fn() => screens_value([['title' => 'Übersicht', 'rows' => $screens[0]['rows']]]), 422);
rejects(fn() => screens_value(array_fill(0, 7, $screens[0])), 422);
rejects(fn() => id_value('../config'), 422);
rejects(fn() => text_value("header\nInjection", 30), 422);
rejects(fn() => bool_value(1), 422);
$device = ['id' => 'flur', 'name' => 'Flur', 'enabled' => true, 'refresh' => 60, 'apps' => ['energy'], 'version' => 0];
$registered = save_device($db, $device);
check(strlen($registered['token']) === 64, 'Device token');
$record = $db->query('SELECT * FROM devices')->fetch();
$manifest = device_manifest($db, $record);
check(count($manifest['apps']) === 1 && !isset($manifest['apps'][0]['token_hash']), 'Scoped manifest');
$device['version'] = 1; $device['apps'] = ['missing'];
rejects(fn() => save_device($db, $device), 422);
check((int) $db->query('SELECT COUNT(*) FROM assignments')->fetchColumn() === 1, 'Rollback retains assignments');
$app['version'] = 1; $app['enabled'] = false; save_app($db, $app);
check(device_manifest($db, $record)['apps'] === [], 'Disabled apps excluded');
$rotated = change_record($db, ['kind' => 'device', 'id' => 'flur', 'version' => 1], true);
check($rotated['token'] !== $registered['token'], 'Token rotated');
rejects(fn() => change_record($db, ['kind' => 'device', 'id' => 'flur', 'version' => 1], true), 409);
change_record($db, ['kind' => 'app', 'id' => 'energy', 'version' => 2], false);
check((int) $db->query('SELECT COUNT(*) FROM assignments')->fetchColumn() === 0, 'Cascade removes assignment');
rate_limit($db, 'test', 2); rate_limit($db, 'test', 2);
rejects(fn() => rate_limit($db, 'test', 2), 429);
check((int) $db->query('SELECT COUNT(*) FROM audit')->fetchColumn() >= 5, 'Audit recorded');
// Exercise the maximum allowed manifest against the device memory budget.
$screens = array_fill(0, 6, ['title' => str_repeat('t', 28), 'rows' => array_fill(0, 3, ['label' => str_repeat('l', 14), 'value' => str_repeat('v', 22)])]);
$ids = [];
for ($i = 0; $i < 10; $i++) {
    $id = 'app-' . $i; $ids[] = $id;
    save_app($db, ['id' => $id, 'title' => str_repeat('t', 24), 'enabled' => true, 'ttl' => 60, 'screens' => $screens, 'version' => 0]);
}
$device['version'] = 2; $device['apps'] = $ids; save_device($db, $device);
$manifest = device_manifest($db, $db->query('SELECT * FROM devices')->fetch());
$bytes = strlen(encode_json($manifest));
check($bytes <= 32768, 'Maximum manifest fits');
echo "$count server checks passed; maximum manifest: $bytes bytes\n";
