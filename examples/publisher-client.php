<?php
declare(strict_types=1);
// Shared CLI transport: fixed HTTPS origin, verified TLS, bounded I/O, no retries.
if (PHP_SAPI !== 'cli') { exit(1); }
$base_url = getenv('BADGER_URL') ?: '';
$app_token = getenv('BADGER_APP_TOKEN') ?: '';
if (!preg_match('~^https://[a-zA-Z0-9.-]+(?::[0-9]+)?$~D', $base_url) || !preg_match('/^[a-f0-9]{64}$/D', $app_token)) {
    throw new RuntimeException('Set BADGER_URL to an HTTPS origin and BADGER_APP_TOKEN to a publisher token');
}
function request_api(string $base_url, string $app_token, string $route, ?array $body = null): array
{
    $curl = curl_init($base_url . '/api.php?r=' . $route);
    $response = '';
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $app_token, 'Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > 16384) { return 0; }
            $response .= $chunk; return strlen($chunk);
        },
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $success = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($success === false || $status !== 200) {
        throw new RuntimeException("Publisher request failed (HTTP $status); no automatic replay");
    }
    return json_decode($response, true, 16, JSON_THROW_ON_ERROR);
}
