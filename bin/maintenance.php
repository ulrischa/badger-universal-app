<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/core.php';
if (PHP_SAPI !== 'cli') { exit(1); }
umask(0077);
$config = load_config();
$command = $argv[1] ?? '';
if ($command === 'password') {
    fwrite(STDERR, "New password (16–72 bytes) on stdin: ");
    $password = rtrim(fgets(STDIN, 256) ?: '', "\r\n");
    if (strlen($password) < 16 || strlen($password) > 72) { throw new RuntimeException('Invalid password length'); }
    $config['admin_hash'] = password_hash($password, PASSWORD_DEFAULT);
    $path = config_path();
    $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    if (file_put_contents($temporary, encode_json($config), LOCK_EX) === false || !rename($temporary, $path)) {
        throw new RuntimeException('Cannot replace configuration');
    }
    echo "Password replaced. Existing admin sessions are now invalid.\n";
} elseif ($command === 'backup') {
    $destination = $argv[2] ?? '';
    if ($destination === '' || is_file($destination) || !is_dir(dirname($destination))) {
        throw new RuntimeException('Supply an unused backup filename in a private directory');
    }
    $db = connect_db($config);
    query_db($db, 'VACUUM INTO ?', [$destination]);
    chmod($destination, 0600);
    echo "Consistent SQLite backup created. Also back up config.json separately.\n";
} elseif ($command === 'cleanup') {
    $removed = 0;
    foreach (new DirectoryIterator(dirname($config['db']) . '/sessions') as $file) {
        if (!$file->isFile() || !str_starts_with($file->getFilename(), 'sess_') || $file->getMTime() >= time() - 28800) { continue; }
        $handle = fopen($file->getPathname(), 'r+');
        if ($handle && flock($handle, LOCK_EX | LOCK_NB)) {
            clearstatcache(true, $file->getPathname());
            if (filemtime($file->getPathname()) < time() - 28800 && unlink($file->getPathname())) { $removed++; }
        }
        if ($handle) { fclose($handle); }
    }
    query_db(connect_db($config), 'DELETE FROM limits WHERE expires<?', [time()]);
    echo "$removed expired sessions removed.\n";
} else {
    fwrite(STDERR, "Usage: php bin/maintenance.php password|backup /private/backup.sqlite|cleanup\n"); exit(1);
}
