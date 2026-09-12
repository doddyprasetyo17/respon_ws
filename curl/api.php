<?php

declare(strict_types=1);

/**
 * Endpoint JSON yang menjalankan satu siklus probe.
 * Dipanggil app.js secara berkala; tidak dimaksudkan dibuka manusia langsung.
 */

require __DIR__ . '/lib/monitor.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

/**
 * @param array<string, mixed> $payload
 */
function api_send(array $payload, int $httpStatus = 200): void
{
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $config = monitor_load_config(__DIR__ . '/config.php');
} catch (Throwable $e) {
    api_send(['error' => $e->getMessage()], 503);
}

// Konfigurasi menentukan apakah error PHP boleh bocor ke respons.
$debug = (bool) $config['debug'];
error_reporting($debug ? E_ALL : 0);
ini_set('display_errors', $debug ? '1' : '0');
date_default_timezone_set('Asia/Jakarta');

try {
    $targets = monitor_flatten_groups($config['groups']);

    $startedAt = microtime(true);
    $services  = monitor_probe_all($targets, $config);
    $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

    api_send([
        'checked_at'       => date('c'),
        'checked_at_human' => date('H:i:s'),
        'duration_ms'      => $elapsedMs,
        'refresh_interval' => (int) $config['refresh_interval'],
        'summary'          => monitor_summarize($services),
        'services'         => $services,
    ]);
} catch (Throwable $e) {
    api_send([
        'error' => $debug
            ? $e->getMessage()
            : 'Gagal menjalankan pengecekan. Nyalakan "debug" di config.php untuk melihat detailnya.',
    ], 500);
}
