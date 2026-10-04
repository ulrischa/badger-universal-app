<?php
declare(strict_types=1);

final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

function fail(int $status, string $message): never
{
    throw new HttpError($status, $message);
}

function encode_json(mixed $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function config_path(): string
{
    return getenv('BADGER_CONFIG') ?: dirname(__DIR__) . '/var/config.json';
}

function load_config(): array
{
    $path = config_path();
    if (!is_file($path)) {
        fail(503, 'Installation fehlt. Bitte bin/setup.php ausführen.');
    }
    $config = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($config) || !isset($config['admin_hash'], $config['db'], $config['origin'])) {
        throw new RuntimeException('Invalid configuration');
    }
    return $config;
}

function connect_db(array $config): PDO
{
    $db = new PDO('sqlite:' . $config['db'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 2,
    ]);
    $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=2000');
    return $db;
}

function migrate_db(PDO $db): void
{
    if ((int) $db->query('PRAGMA user_version')->fetchColumn() > 1) {
        throw new RuntimeException('Database schema is newer than this application');
    }
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS apps (
        id TEXT PRIMARY KEY, title TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1,
        ttl INTEGER NOT NULL, screens TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE,
        updated_at INTEGER NOT NULL, version INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS devices (
        id TEXT PRIMARY KEY, name TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE,
        enabled INTEGER NOT NULL DEFAULT 1, refresh INTEGER NOT NULL DEFAULT 900,
        version INTEGER NOT NULL DEFAULT 1, last_seen INTEGER
    );
    CREATE TABLE IF NOT EXISTS assignments (
        device_id TEXT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
        app_id TEXT NOT NULL REFERENCES apps(id) ON DELETE CASCADE,
        position INTEGER NOT NULL, PRIMARY KEY(device_id, app_id)
    );
    CREATE TABLE IF NOT EXISTS limits (
        bucket TEXT PRIMARY KEY, window INTEGER NOT NULL, count INTEGER NOT NULL, expires INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS audit (
        id INTEGER PRIMARY KEY, at INTEGER NOT NULL, event TEXT NOT NULL, target TEXT NOT NULL
    ); PRAGMA user_version=1');
}

function query_db(PDO $db, string $sql, array $params = []): PDOStatement
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function transaction(PDO $db, callable $work): mixed
{
    // Acquire the write reservation before reading to avoid lock upgrades.
    $db->exec('BEGIN IMMEDIATE');
    try {
        $result = $work();
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function rate_limit(PDO $db, string $bucket, int $maximum, int $seconds = 60): void
{
    $window = intdiv(time(), $seconds);
    $count = transaction($db, static function () use ($db, $bucket, $window, $seconds): int {
        query_db($db, 'DELETE FROM limits WHERE expires<?', [time()]);
        query_db($db, 'INSERT INTO limits(bucket,window,count,expires) VALUES(?,?,1,?)
            ON CONFLICT(bucket) DO UPDATE SET window=excluded.window, expires=excluded.expires,
            count=CASE WHEN limits.window=excluded.window THEN limits.count+1 ELSE 1 END', [$bucket, $window, ($window + 1) * $seconds]);
        return (int) query_db($db, 'SELECT count FROM limits WHERE bucket=?', [$bucket])->fetchColumn();
    });
    if ($count > $maximum) {
        header('Retry-After: ' . $seconds);
        fail(429, 'Zu viele Anfragen. Bitte später erneut versuchen.');
    }
}

function audit_event(PDO $db, string $event, string $target): void
{
    query_db($db, 'INSERT INTO audit(at,event,target) VALUES(?,?,?)', [time(), $event, $target]);
    // Bound operational storage without retaining credentials or payloads.
    $db->exec('DELETE FROM audit WHERE id <= (SELECT COALESCE(MAX(id),0)-2000 FROM audit)');
}

function exact_keys(array $data, array $keys): void
{
    if (array_diff(array_keys($data), $keys) || array_diff($keys, array_keys($data))) {
        fail(422, 'Fehlende oder unbekannte Felder.');
    }
}

function text_value(mixed $value, int $max, bool $ascii = false): string
{
    if (!is_string($value) || $value === '' || strlen($value) > $max ||
        preg_match('/[\x00-\x1F\x7F]/', $value) || !preg_match('//u', $value) ||
        ($ascii && preg_match('/[^\x20-\x7E]/', $value))) {
        fail(422, 'Ungültiger Text oder Text zu lang. Displaytexte benötigen druckbares ASCII.');
    }
    return $value;
}

function id_value(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $value)) {
        fail(422, 'ID: 1–32 Zeichen, Kleinbuchstaben, Ziffern und Bindestriche.');
    }
    return $value;
}

function int_value(mixed $value, int $min, int $max): int
{
    if (!is_int($value) || $value < $min || $value > $max) {
        fail(422, "Zahl muss zwischen $min und $max liegen.");
    }
    return $value;
}

function bool_value(mixed $value): bool
{
    if (!is_bool($value)) {
        fail(422, 'Boolean erwartet.');
    }
    return $value;
}

function screens_value(mixed $screens): array
{
    if (!is_array($screens) || !array_is_list($screens) || count($screens) < 1 || count($screens) > 6) {
        fail(422, '1–6 Seiten erwartet.');
    }
    foreach ($screens as $screen) {
        if (!is_array($screen)) {
            fail(422, 'Ungültige Seite.');
        }
        exact_keys($screen, ['title', 'rows']);
        text_value($screen['title'], 28, true);
        if (!is_array($screen['rows']) || !array_is_list($screen['rows']) || count($screen['rows']) < 1 || count($screen['rows']) > 3) {
            fail(422, '1–3 Zeilen je Seite erwartet.');
        }
        foreach ($screen['rows'] as $row) {
            if (!is_array($row)) {
                fail(422, 'Ungültige Zeile.');
            }
            exact_keys($row, ['label', 'value']);
            text_value($row['label'], 14, true);
            text_value($row['value'], 22, true);
        }
    }
    return $screens;
}

function new_token(): string
{
    return bin2hex(random_bytes(32));
}

function read_body(): array
{
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        fail(415, 'application/json erforderlich.');
    }
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if ($raw === false || strlen($raw) > 16384) {
        fail(413, 'Anfrage zu groß.');
    }
    try {
        $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail(400, 'Ungültiges JSON.');
    }
    if (!is_array($body) || array_is_list($body)) {
        fail(400, 'JSON-Objekt erwartet.');
    }
    return $body;
}

function bearer_record(PDO $db, string $kind): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/D', $header, $match)) {
        fail(401, 'Ungültiger Schlüssel.');
    }
    $table = $kind === 'device' ? 'devices' : 'apps';
    $record = query_db($db, "SELECT * FROM $table WHERE token_hash=? AND enabled=1", [hash('sha256', $match[1])])->fetch();
    if (!$record) {
        fail(401, 'Ungültiger Schlüssel.');
    }
    rate_limit($db, $kind . ':' . $record['id'], $kind === 'device' ? 120 : 60);
    return $record;
}

function device_manifest(PDO $db, array $device): array
{
    $apps = query_db($db, 'SELECT apps.* FROM assignments JOIN apps ON apps.id=assignments.app_id
        WHERE device_id=? AND enabled=1 ORDER BY position, apps.id', [$device['id']])->fetchAll();
    $result = ['schema' => 1, 'generated_at' => time(), 'refresh_seconds' => (int) $device['refresh'], 'apps' => []];
    foreach ($apps as $app) {
        $result['apps'][] = [
            'id' => $app['id'], 'title' => $app['title'],
            'updated_at' => (int) $app['updated_at'], 'ttl' => (int) $app['ttl'],
            'screens' => json_decode($app['screens'], true, 16, JSON_THROW_ON_ERROR),
        ];
    }
    if (strlen(encode_json($result)) > 32768) {
        throw new RuntimeException('Manifest exceeds device budget');
    }
    return $result;
}
