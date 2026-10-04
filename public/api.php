<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/core.php';
require dirname(__DIR__) . '/src/admin.php';
require dirname(__DIR__) . '/src/session.php';
umask(0077);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');

function respond(mixed $data, int $status = 200): never
{
    $json = encode_json($data);
    http_response_code($status);
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

try {
    $config = load_config();
    $secure = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTPS'] ?? '') === '1';
    $local_dev = ($config['development'] ?? false) && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (!$secure && !$local_dev) { fail(403, 'HTTPS erforderlich.'); }
    if ($secure) { header('Strict-Transport-Security: max-age=31536000'); }
    $route = $_GET['r'] ?? '';
    if (!is_string($route)) { fail(404, 'Nicht gefunden.'); }
    $method = $_SERVER['REQUEST_METHOD'];
    $routes = ['session' => 'GET', 'state' => 'GET', 'manifest' => 'GET', 'app-status' => 'GET', 'login' => 'POST', 'logout' => 'POST',
        'app' => 'POST', 'device' => 'POST', 'delete' => 'POST', 'rotate' => 'POST', 'publish' => 'POST'];
    if (!isset($routes[$route])) { fail(404, 'Nicht gefunden.'); }
    if ($method !== $routes[$route]) { header('Allow: ' . $routes[$route]); fail(405, 'Methode nicht erlaubt.'); }
    $db = connect_db($config);
    if (in_array($route, ['manifest', 'publish', 'app-status'], true)) {
        $record = bearer_record($db, $route === 'manifest' ? 'device' : 'app');
        if ($route === 'manifest') {
            query_db($db, 'UPDATE devices SET last_seen=? WHERE id=?', [time(), $record['id']]);
            respond(device_manifest($db, $record));
        }
        if ($route === 'app-status') { respond(['id' => $record['id'], 'version' => (int) $record['version'], 'updated_at' => (int) $record['updated_at']]); }
        $body = read_body();
        exact_keys($body, ['screens', 'version']);
        $screens = encode_json(screens_value($body['screens']));
        $version = int_value($body['version'], 1, PHP_INT_MAX - 1);
        $updated = query_db($db, 'UPDATE apps SET screens=?,updated_at=?,version=version+1 WHERE id=? AND version=? AND token_hash=? AND enabled=1',
            [$screens, time(), $record['id'], $version, $record['token_hash']]);
        if ($updated->rowCount() !== 1) { fail(409, 'Version veraltet. Aktuelle Version im Header X-App-Version.'); }
        respond(['version' => $version + 1]);
    }
    // Reject cross-origin requests before opening a session.
    if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== $config['origin']) { fail(403, 'Origin nicht erlaubt.'); }
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') { fail(403, 'Cross-Site-Anfrage nicht erlaubt.'); }
    session_set_save_handler(new BoundedSessionHandler(dirname($config['db']) . '/sessions'), true);
    ini_set('session.gc_maxlifetime', '28800');
    session_name('badger_session');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !$local_dev,
        'httponly' => true, 'samesite' => 'Strict']);
    if (!session_start()) { fail(503, 'Sitzungsspeicher nicht verfügbar.'); }
    $_SESSION['csrf'] ??= new_token();
    $csrf = $_SESSION['csrf'];
    $authenticated = isset($_SESSION['login_at']) && time() - $_SESSION['login_at'] < 28800 &&
        hash_equals($_SESSION['auth_version'] ?? '', hash('sha256', $config['admin_hash']));
    if ($route === 'session') {
        session_write_close();
        respond(['authenticated' => $authenticated, 'csrf' => $csrf]);
    }
    if ($method !== 'GET' && !hash_equals($csrf, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        session_write_close(); fail(403, 'Sitzung ungültig. Bitte Seite neu laden.');
    }
    if ($route === 'login') {
        // Password verification and database waits must not hold the session lock.
        session_write_close();
        rate_limit($db, 'login-global', 100, 900);
        $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        rate_limit($db, 'login:' . $ip, 10, 900);
        $body = read_body(); exact_keys($body, ['password']);
        if (!is_string($body['password']) || strlen($body['password']) > 72 || !password_verify($body['password'], $config['admin_hash'])) {
            fail(401, 'Anmeldung fehlgeschlagen.');
        }
        if (!session_start()) { fail(503, 'Sitzungsspeicher nicht verfügbar.'); } session_regenerate_id(true);
        $_SESSION = ['login_at' => time(), 'csrf' => new_token(), 'auth_version' => hash('sha256', $config['admin_hash'])];
        $csrf = $_SESSION['csrf']; session_write_close();
        audit_event($db, 'admin.login', 'admin');
        respond(['csrf' => $csrf]);
    }
    if (!$authenticated) { session_write_close(); fail(401, 'Bitte anmelden.'); }
    if ($route === 'logout') {
        $_SESSION = []; session_destroy();
        setcookie('badger_session', '', ['expires' => 1, 'path' => '/', 'secure' => !$local_dev, 'httponly' => true, 'samesite' => 'Strict']);
        respond(['ok' => true]);
    }
    session_write_close();
    if ($route === 'state') { respond(admin_state($db)); }
    $body = read_body();
    respond(match ($route) {
        'app' => save_app($db, $body), 'device' => save_device($db, $body),
        'rotate' => change_record($db, $body, true), 'delete' => change_record($db, $body, false),
        default => throw new HttpError(404, 'Nicht gefunden.'),
    });
} catch (HttpError $error) {
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    if (($route ?? '') === 'publish' && isset($record, $db) && $error->status === 409) {
        $current = query_db($db, 'SELECT version FROM apps WHERE id=?', [$record['id']])->fetchColumn();
        header('X-App-Version: ' . (int) $current);
    }
    respond(['error' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    $busy = $error instanceof PDOException && in_array((int) ($error->errorInfo[1] ?? 0), [5, 6], true);
    error_log('Badger Hub: ' . get_class($error) . ($busy ? ' database busy' : ' internal failure'));
    if ($busy) { header('Retry-After: 3'); }
    respond(['error' => $busy ? 'Datenbank beschäftigt. Bitte erneut versuchen.' : 'Interner Fehler. Serverprotokoll prüfen.'], $busy ? 503 : 500);
}
