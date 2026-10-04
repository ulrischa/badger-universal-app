<?php
declare(strict_types=1);

function admin_state(PDO $db): array
{
    $apps = query_db($db, 'SELECT id,title,enabled,ttl,screens,updated_at,version FROM apps ORDER BY id')->fetchAll();
    foreach ($apps as &$app) {
        $app['enabled'] = (bool) $app['enabled'];
        $app['screens'] = json_decode($app['screens'], true, 16, JSON_THROW_ON_ERROR);
    }
    unset($app);
    $devices = query_db($db, 'SELECT id,name,enabled,refresh,version,last_seen FROM devices ORDER BY id')->fetchAll();
    foreach ($devices as &$device) {
        $device['enabled'] = (bool) $device['enabled'];
        $device['apps'] = query_db($db, 'SELECT app_id FROM assignments WHERE device_id=? ORDER BY position', [$device['id']])->fetchAll(PDO::FETCH_COLUMN);
    }
    return ['apps' => $apps, 'devices' => $devices,
        'audit' => query_db($db, 'SELECT at,event,target FROM audit ORDER BY id DESC LIMIT 20')->fetchAll()];
}

function save_app(PDO $db, array $body): array
{
    exact_keys($body, ['id', 'title', 'enabled', 'ttl', 'screens', 'version']);
    $id = id_value($body['id']);
    $title = text_value($body['title'], 24, true);
    $enabled = bool_value($body['enabled']);
    $ttl = int_value($body['ttl'], 60, 604800);
    $screens = encode_json(screens_value($body['screens']));
    $version = int_value($body['version'], 0, PHP_INT_MAX - 1);
    return transaction($db, static function () use ($db, $id, $title, $enabled, $ttl, $screens, $version): array {
        $old = query_db($db, 'SELECT version FROM apps WHERE id=?', [$id])->fetchColumn();
        if (($old === false && $version !== 0) || ($old !== false && (int) $old !== $version)) {
            fail(409, 'App wurde geändert. Liste neu laden und Änderungen prüfen.');
        }
        $token = null;
        if ($old === false) {
            if ((int) $db->query('SELECT COUNT(*) FROM apps')->fetchColumn() >= 100) {
                fail(422, 'Maximal 100 Apps.');
            }
            $token = new_token();
            query_db($db, 'INSERT INTO apps(id,title,enabled,ttl,screens,token_hash,updated_at) VALUES(?,?,?,?,?,?,?)',
                [$id, $title, (int) $enabled, $ttl, $screens, hash('sha256', $token), time()]);
        } else {
            query_db($db, 'UPDATE apps SET title=?,enabled=?,ttl=?,screens=?,updated_at=?,version=version+1 WHERE id=?',
                [$title, (int) $enabled, $ttl, $screens, time(), $id]);
        }
        audit_event($db, 'app.saved', $id);
        return ['id' => $id, 'token' => $token];
    });
}

function save_device(PDO $db, array $body): array
{
    exact_keys($body, ['id', 'name', 'enabled', 'refresh', 'apps', 'version']);
    $id = id_value($body['id']);
    $name = text_value($body['name'], 64);
    $enabled = bool_value($body['enabled']);
    $refresh = int_value($body['refresh'], 60, 86400);
    $version = int_value($body['version'], 0, PHP_INT_MAX - 1);
    $apps = $body['apps'];
    if (!is_array($apps) || !array_is_list($apps) || count($apps) > 10) {
        fail(422, 'Maximal zehn Apps je Gerät.');
    }
    foreach ($apps as $app) {
        id_value($app);
    }
    if (count(array_unique($apps)) !== count($apps)) {
        fail(422, 'Doppelte App-Zuweisung.');
    }
    return transaction($db, static function () use ($db, $id, $name, $enabled, $refresh, $version, $apps): array {
        $old = query_db($db, 'SELECT version FROM devices WHERE id=?', [$id])->fetchColumn();
        if (($old === false && $version !== 0) || ($old !== false && (int) $old !== $version)) {
            fail(409, 'Gerät wurde geändert. Liste neu laden und Änderungen prüfen.');
        }
        foreach ($apps as $app) {
            if (!query_db($db, 'SELECT 1 FROM apps WHERE id=?', [$app])->fetchColumn()) {
                fail(422, 'Zugewiesene App existiert nicht.');
            }
        }
        $token = null;
        if ($old === false) {
            if ((int) $db->query('SELECT COUNT(*) FROM devices')->fetchColumn() >= 100) {
                fail(422, 'Maximal 100 Geräte.');
            }
            $token = new_token();
            query_db($db, 'INSERT INTO devices(id,name,enabled,refresh,token_hash) VALUES(?,?,?,?,?)',
                [$id, $name, (int) $enabled, $refresh, hash('sha256', $token)]);
        } else {
            query_db($db, 'UPDATE devices SET name=?,enabled=?,refresh=?,version=version+1 WHERE id=?',
                [$name, (int) $enabled, $refresh, $id]);
        }
        query_db($db, 'DELETE FROM assignments WHERE device_id=?', [$id]);
        foreach ($apps as $position => $app) {
            query_db($db, 'INSERT INTO assignments(device_id,app_id,position) VALUES(?,?,?)', [$id, $app, $position]);
        }
        audit_event($db, 'device.saved', $id);
        return ['id' => $id, 'token' => $token];
    });
}

function change_record(PDO $db, array $body, bool $rotate): array
{
    exact_keys($body, ['kind', 'id', 'version']);
    if (!in_array($body['kind'], ['app', 'device'], true)) {
        fail(422, 'Ungültiger Typ.');
    }
    $table = $body['kind'] === 'app' ? 'apps' : 'devices';
    $id = id_value($body['id']);
    $version = int_value($body['version'], 1, PHP_INT_MAX - 1);
    return transaction($db, static function () use ($db, $body, $table, $id, $version, $rotate): array {
        $token = $rotate ? new_token() : null;
        $statement = $rotate
            ? query_db($db, "UPDATE $table SET token_hash=?,version=version+1 WHERE id=? AND version=?", [hash('sha256', $token), $id, $version])
            : query_db($db, "DELETE FROM $table WHERE id=? AND version=?", [$id, $version]);
        if ($statement->rowCount() !== 1) {
            fail(409, 'Eintrag wurde geändert. Bitte neu laden.');
        }
        audit_event($db, $body['kind'] . ($rotate ? '.rotated' : '.deleted'), $id);
        return ['id' => $id, 'token' => $token];
    });
}
