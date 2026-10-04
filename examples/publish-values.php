<?php
declare(strict_types=1);
// Read one complete data snapshot from stdin; execute once, never as a daemon.
require __DIR__ . '/publisher-client.php';
$raw = stream_get_contents(STDIN, 16385);
if ($raw === false || strlen($raw) > 16384) { throw new RuntimeException('Input exceeds 16 KiB'); }
$values = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
if (!$values instanceof stdClass) { throw new RuntimeException('Expected a JSON object of data fields'); }
$current = request_api($base_url, $app_token, 'app-status');
if (($current['publish_mode'] ?? '') !== 'values') { throw new RuntimeException('Select hub-managed layout for this app first'); }
$result = request_api($base_url, $app_token, 'publish-values', ['version' => $current['data_version'], 'values' => $values]);
echo 'Published data version ' . $result['data_version'] . PHP_EOL;
