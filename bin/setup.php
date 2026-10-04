<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/core.php';
if (PHP_SAPI !== 'cli') { exit(1); }
umask(0077);
$origin = $argv[1] ?? '';
$dev = ($argv[2] ?? '') === '--dev';
$parts = parse_url($origin);
if (!$parts || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) ||
    !isset($parts['host']) || (($parts['scheme'] ?? '') !== 'https' && !($dev && $origin === 'http://127.0.0.1:8080'))) {
    fwrite(STDERR, "Usage: php bin/setup.php https://badge.example.com\nLocal only: php bin/setup.php http://127.0.0.1:8080 --dev\n"); exit(1);
}
$path = config_path();
if (is_file($path)) { fwrite(STDERR, "Configuration already exists. No changes made.\n"); exit(1); }
fwrite(STDERR, "Admin password (16–72 bytes) on stdin; use a pipe or disable terminal echo: ");
$password = rtrim(fgets(STDIN, 256) ?: '', "\r\n");
if (strlen($password) < 16 || strlen($password) > 72) { fwrite(STDERR, "Invalid password length.\n"); exit(1); }
$directory = dirname($path);
if (!is_dir($directory) && !mkdir($directory, 0700, true)) { throw new RuntimeException('Cannot create data directory'); }
$config = ['origin' => $origin, 'development' => $dev,
    'admin_hash' => password_hash($password, PASSWORD_DEFAULT), 'db' => $directory . '/hub.sqlite'];
if (!is_dir($directory . '/sessions')) { mkdir($directory . '/sessions', 0700); }
$db = connect_db($config);
migrate_db($db);
$handle = fopen($path, 'x');
if (!$handle) { throw new RuntimeException('Cannot create configuration'); }
fwrite($handle, encode_json($config)); fclose($handle);
fwrite(STDOUT, "Setup complete. Point the web server document root at public/.\n");
