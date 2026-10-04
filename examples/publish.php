<?php
declare(strict_types=1);
// Legacy whole-page publisher. For hub-managed layouts use publish-values.php.
require __DIR__ . '/publisher-client.php';
$current = request_api($base_url, $app_token, 'app-status');
// Replace these sample values with values from your own data source.
$screens = [['title' => 'Energie heute', 'rows' => [
    ['label' => 'PV', 'value' => '5.8 kW'],
    ['label' => 'Akku', 'value' => '84 %'],
    ['label' => 'Netz', 'value' => '-2.1 kW'],
]]];
$result = request_api($base_url, $app_token, 'publish', ['version' => $current['version'], 'screens' => $screens]);
echo 'Published version ' . $result['version'] . PHP_EOL;
