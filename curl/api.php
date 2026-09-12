<?php

declare(strict_types=1);

/**
 * Endpoint JSON yang menjalankan satu siklus probe.
 * Dipanggil app.js secara berkala; tidak dimaksudkan dibuka manusia langsung.
 */

require __DIR__ . '/lib/monitor.php';
require __DIR__ . '/lib/history.php';

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

    $stripLength = max(10, (int) $config['strip_length']);

    // Riwayat bersifat tambahan. Kalau foldernya tidak bisa ditulis, dashboard
    // tetap berfungsi penuh -- hanya grafik dan persentase yang kosong.
    $stats       = [];
    $historyNote = null;

    if (!empty($config['history_enabled'])) {
        $dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';

        if (history_ensure_dir($dataDir)) {
            $samples = history_append(history_file($dataDir), $services, $config);
            $stats   = history_stats($samples, $services, $stripLength);
        } else {
            $historyNote = 'Folder curl/data tidak bisa ditulis, jadi riwayat tidak tersimpan.';
        }
    }

    foreach ($services as $i => $service) {
        $stat = $stats[$service['id']] ?? [];

        $services[$i] += [
            'availability'    => $stat['availability'] ?? null,
            'history_samples' => $stat['samples'] ?? 0,
            'outages'         => $stat['outages'] ?? 0,
            'since'           => $stat['since'] ?? null,
            'delta_ms'        => $stat['delta_ms'] ?? null,
            'baseline_ms'     => $stat['baseline_ms'] ?? null,
            'strip'           => $stat['strip'] ?? [],
            'latencies'       => $stat['latencies'] ?? [],
        ];
    }

    $windowMinutes = (int) round(
        ((int) $config['history_limit']) * ((int) $config['refresh_interval']) / 60
    );

    api_send([
        'checked_at'       => date('c'),
        'checked_at_human' => date('H:i:s'),
        'server_time'      => time(),
        'duration_ms'      => $elapsedMs,
        'refresh_interval' => (int) $config['refresh_interval'],
        'threshold_good'   => (int) $config['threshold_good'],
        'threshold_slow'   => (int) $config['threshold_slow'],
        'window_minutes'   => $windowMinutes,
        'history_note'     => $historyNote,
        'summary'          => monitor_summarize($services),
        'incidents'        => history_incidents($services, $stats),
        'services'         => $services,
    ]);
} catch (Throwable $e) {
    api_send([
        'error' => $debug
            ? $e->getMessage()
            : 'Gagal menjalankan pengecekan. Nyalakan "debug" di config.php untuk melihat detailnya.',
    ], 500);
}
