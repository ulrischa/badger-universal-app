<?php
declare(strict_types=1);
require __DIR__ . '/server.php';

$layout = [['title' => 'Room', 'rows' => [
    ['label' => 'Temp', 'field' => 'temperature', 'unit' => 'deg C', 'decimals' => 1],
    ['label' => 'Status', 'value' => 'Local'],
]]];
$body = ['id' => 'bound', 'title' => 'Room', 'enabled' => true, 'ttl' => 60,
    'screens' => $layout, 'version' => 0, 'publish_mode' => 'values'];
$created = save_app($db, $body);
$get_app = fn() => query_db($db, 'SELECT * FROM apps WHERE id=?', ['bound'])->fetch();
$record = $get_app();
check(app_updated_at($record) === 0, 'New binding has no fresh data');
check(app_screens($record)[0]['rows'][0]['value'] === '--', 'Missing data placeholder');
$first = publish_values($db, $record, ['version' => 0, 'values' => ['temperature' => 21.25]]);
check($first['data_version'] === 1, 'Independent data revision');
check((int) $get_app()['version'] === 1, 'Publisher cannot change layout revision');
check(app_screens($get_app())[0]['rows'][0]['value'] === '21.3 deg C', 'Server numeric formatting');
rejects(fn() => publish_values($db, $record, ['version' => 0, 'values' => ['temperature' => 5]]), 409);
// A layout save must preserve a data write that happened after the editor loaded.
$body['version'] = 1;
$body['screens'][0]['title'] = 'Edited';
$body['screens'][0]['rows'][0]['decimals'] = 2;
$timestamp = $get_app()['data_updated_at'];
save_app($db, $body);
check(app_screens($get_app())[0]['rows'][0]['value'] === '21.25 deg C', 'Layout save retains newest data');
check($get_app()['data_updated_at'] === $timestamp, 'Layout save does not refresh data timestamp');
publish_values($db, $record, ['version' => 1, 'values' => ['temperature' => 22]]);
check(app_screens($get_app())[0]['title'] === 'Edited', 'Data write retains layout edits');
check((int) $get_app()['version'] === 2, 'Data does not invalidate editor version');
publish_values($db, $record, ['version' => 2, 'values' => ['other' => 'ok']]);
check(app_screens($get_app())[0]['rows'][0]['value'] === '--', 'Snapshot removes omitted fields');
check(render_layout($layout, ['temperature' => null])[0]['rows'][0]['value'] === '--', 'Null removes value');
check(render_layout($layout, ['temperature' => str_repeat('x', 22)])[0]['rows'][0]['value'] === 'OVERFLOW', 'No silent display truncation');
foreach ([['nested' => []], ['flag' => true], ['Temperature' => 1], ['x' => INF], ['x' => 1e13], ['x' => 'ü']] as $invalid) {
    rejects(fn() => values_value($invalid), 422);
}
rejects(fn() => values_value(array_fill_keys(array_map(fn($i) => 'f' . $i, range(0, 32)), 1)), 422);
rejects(fn() => layout_value([['title' => 'X', 'rows' => [['label' => 'A', 'field' => '../x', 'unit' => '', 'decimals' => 1]]]]), 422);
rejects(fn() => screens_value($layout), 422);
// Mode changes clear data and advance its revision, blocking old in-flight snapshots.
$body['version'] = 2; $body['publish_mode'] = 'pages'; $body['screens'] = $screens;
save_app($db, $body);
rejects(fn() => publish_values($db, $record, ['version' => 3, 'values' => ['temperature' => 7]]), 409);
$body['version'] = 3; $body['publish_mode'] = 'values'; $body['screens'] = $layout;
save_app($db, $body);
check((int) $get_app()['data_version'] === 5 && app_updated_at($get_app()) === 0, 'Mode round-trip rejects obsolete revisions and freshness');
rejects(fn() => publish_values($db, $record, ['version' => 3, 'values' => ['temperature' => 7]]), 409);
change_record($db, ['kind' => 'app', 'id' => 'bound', 'version' => 4], true);
rejects(fn() => publish_values($db, $record, ['version' => 5, 'values' => ['temperature' => 7]]), 409);
// Simulate an installed v1 schema with an existing app.
$legacy = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$legacy->exec('CREATE TABLE apps (id TEXT PRIMARY KEY,title TEXT NOT NULL,enabled INTEGER NOT NULL DEFAULT 1,
    ttl INTEGER NOT NULL,screens TEXT NOT NULL,token_hash TEXT NOT NULL UNIQUE,updated_at INTEGER NOT NULL,version INTEGER NOT NULL DEFAULT 1);
    PRAGMA user_version=1');
query_db($legacy, 'INSERT INTO apps VALUES(?,?,?,?,?,?,?,?)', ['existing', 'Existing', 1, 60, encode_json($screens), 'hash', 123, 4]);
migrate_db($legacy); migrate_db($legacy);
$preserved = $legacy->query('SELECT * FROM apps')->fetch();
check($preserved['publish_mode'] === 'pages' && $preserved['version'] === 4 && $preserved['updated_at'] === 123, 'Idempotent migration preserves existing app');
check(app_screens($preserved) === $screens, 'Migration preserves pages');
echo "$count checks passed including data/layout separation and v1 migration\n";
