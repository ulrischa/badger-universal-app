<?php
declare(strict_types=1);

function field_key(mixed $key): string
{
    if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $key)) {
        fail(422, 'Datenfeld: 1–32 Zeichen, Kleinbuchstabe zuerst, dann a-z, 0-9 oder Unterstrich.');
    }
    return $key;
}

function layout_value(mixed $screens): array
{
    // Reuse device bounds by replacing bindings with a validated placeholder.
    if (!is_array($screens)) { fail(422, 'Seitenarray erwartet.'); }
    $plain = $screens;
    foreach ($screens as $page_index => $screen) {
        if (!is_array($screen) || !isset($screen['rows']) || !is_array($screen['rows'])) { fail(422, 'Ungültige Seite.'); }
        foreach ($screen['rows'] as $row_index => $row) {
            if (!is_array($row)) { fail(422, 'Ungültige Zeile.'); }
            if (array_key_exists('field', $row)) {
                exact_keys($row, ['label', 'field', 'unit', 'decimals']);
                field_key($row['field']);
                if ($row['unit'] !== '') { text_value($row['unit'], 8, true); }
                int_value($row['decimals'], 0, 3);
                $plain[$page_index]['rows'][$row_index] = ['label' => $row['label'], 'value' => '--'];
            }
        }
    }
    screens_value($plain);
    return $screens;
}

function values_value(mixed $values): array
{
    // JSON objects decode to associative arrays; an empty array also represents {}.
    if (!is_array($values) || ($values !== [] && array_is_list($values)) || count($values) > 32) {
        fail(422, 'Bis zu 32 benannte Datenfelder erwartet.');
    }
    foreach ($values as $key => $value) {
        field_key($key);
        if ($value === null) { continue; }
        if (is_string($value)) { text_value($value, 22, true); continue; }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs($value) > 1e12) {
            fail(422, 'Datenwerte: ASCII-Text (1–22 Zeichen), endliche Zahl zwischen -1e12 und 1e12 oder null.');
        }
    }
    return $values;
}

function has_bindings(array $screens): bool
{
    foreach ($screens as $screen) {
        foreach ($screen['rows'] as $row) {
            if (array_key_exists('field', $row)) { return true; }
        }
    }
    return false;
}

function render_layout(array $screens, array $values): array
{
    foreach ($screens as &$screen) {
        foreach ($screen['rows'] as &$row) {
            if (!array_key_exists('field', $row)) { continue; }
            $value = $values[$row['field']] ?? null;
            if ($value === null) {
                $text = '--';
            } else {
                $text = is_string($value) ? $value : number_format((float) $value, $row['decimals'], '.', '');
                if ($row['unit'] !== '') { $text .= ' ' . $row['unit']; }
                if (strlen($text) > 22) { $text = 'OVERFLOW'; }
            }
            $row = ['label' => $row['label'], 'value' => $text];
        }
        unset($row);
    }
    unset($screen);
    return $screens;
}

function app_screens(array $app): array
{
    $screens = json_decode($app['screens'], true, 16, JSON_THROW_ON_ERROR);
    return $app['publish_mode'] === 'values'
        ? render_layout($screens, json_decode($app['values_json'], true, 16, JSON_THROW_ON_ERROR)) : $screens;
}

function app_updated_at(array $app): int
{
    $bound = $app['publish_mode'] === 'values' && has_bindings(json_decode($app['screens'], true, 16, JSON_THROW_ON_ERROR));
    return (int) ($bound ? $app['data_updated_at'] : $app['updated_at']);
}

function publish_values(PDO $db, array $record, array $body): array
{
    exact_keys($body, ['version', 'values']);
    $version = int_value($body['version'], 0, PHP_INT_MAX - 1);
    $values = encode_json((object) values_value($body['values']));
    // One compare-and-swap; layout writes neither conflict with nor get overwritten by data writes.
    $updated = query_db($db, "UPDATE apps SET values_json=?,data_updated_at=?,data_version=data_version+1
        WHERE id=? AND data_version=? AND token_hash=? AND enabled=1 AND publish_mode='values'",
        [$values, time(), $record['id'], $version, $record['token_hash']]);
    if ($updated->rowCount() !== 1) { fail(409, 'Datenversion oder Betriebsart geändert. App-Status erneut lesen.'); }
    return ['data_version' => $version + 1];
}
