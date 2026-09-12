<?php

declare(strict_types=1);

/**
 * Mesin probe untuk dashboard Respon WS.
 *
 * Semua target dicek serentak lewat curl_multi, jadi satu siklus refresh
 * kira-kira selama target paling lambat -- bukan jumlah seluruh target.
 */

function monitor_defaults(): array
{
    return [
        'title'            => 'Respon WS',
        'subtitle'         => '',
        'credit'           => '',
        'refresh_interval' => 30,
        'timeout'          => 3,
        'connect_timeout'  => 3,
        'threshold_good'   => 500,
        'threshold_slow'   => 1500,
        'retry_failed'     => true,
        'user_agent'       => 'respon-ws-monitor/2.0',
        'debug'            => false,
        'groups'           => [],
    ];
}

/**
 * @throws RuntimeException kalau config belum ada atau bentuknya salah.
 */
function monitor_load_config(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException(
            'config.php belum ada. Salin curl/config.example.php menjadi curl/config.php lalu sesuaikan daftar URL-nya.'
        );
    }

    $config = require $file;

    if (!is_array($config)) {
        throw new RuntimeException('config.php harus mengembalikan array.');
    }

    $config += monitor_defaults();

    if (!is_array($config['groups']) || $config['groups'] === []) {
        throw new RuntimeException('config.php tidak berisi satu pun layanan pada key "groups".');
    }

    return $config;
}

/**
 * Kunci identitas sebuah handle cURL.
 *
 * PHP 8 memakai objek CurlHandle, PHP 7 memakai resource. Keduanya perlu
 * dipetakan ke kunci array untuk mencocokkan handle dengan hasil dari
 * curl_multi_info_read().
 *
 * @param mixed $handle
 */
function monitor_handle_key($handle): int
{
    return is_object($handle) ? spl_object_id($handle) : (int) $handle;
}

function monitor_slug(string $text): string
{
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($text));

    return trim((string) $slug, '-') ?: 'layanan';
}

/**
 * Ratakan daftar bertingkat dari config jadi daftar datar siap-probe.
 *
 * Tiap layanan boleh ditulis sebagai string URL, atau array
 * ['url' => ..., 'accept' => [200]] untuk membatasi HTTP code yang dianggap sehat.
 */
function monitor_flatten_groups(array $groups): array
{
    $targets = [];
    $used    = [];

    foreach ($groups as $groupName => $services) {
        if (!is_array($services)) {
            continue;
        }

        foreach ($services as $name => $spec) {
            $spec = is_array($spec) ? $spec : ['url' => $spec];

            $base = monitor_slug((string) $name);
            $id   = $base;
            $n    = 1;
            while (isset($used[$id])) {
                $id = $base . '-' . (++$n);
            }
            $used[$id] = true;

            $targets[] = [
                'id'     => $id,
                'name'   => (string) $name,
                'group'  => (string) $groupName,
                'url'    => trim((string) ($spec['url'] ?? '')),
                'accept' => array_map('intval', (array) ($spec['accept'] ?? [])),
            ];
        }
    }

    return $targets;
}

/**
 * Terjemahkan kode error cURL jadi keterangan yang bisa dibaca operator.
 */
function monitor_error_detail(int $errno): string
{
    switch ($errno) {
        case CURLE_OPERATION_TIMEDOUT:
            return 'Timeout';
        case CURLE_COULDNT_RESOLVE_HOST:
            return 'DNS gagal';
        case CURLE_COULDNT_RESOLVE_PROXY:
            return 'Proxy tidak ditemukan';
        case CURLE_COULDNT_CONNECT:
            return 'Koneksi ditolak';
        case CURLE_SSL_CONNECT_ERROR:
        case CURLE_SSL_CACERT:
            return 'Handshake SSL gagal';
        case CURLE_GOT_NOTHING:
            return 'Server menutup koneksi';
        case CURLE_TOO_MANY_REDIRECTS:
            return 'Terlalu banyak redirect';
        default:
            return 'Tidak dapat dihubungi';
    }
}

/**
 * Tentukan status akhir sebuah target.
 *
 * Catatan penting: endpoint BPJS dan Satu Sehat adalah API ber-autentikasi
 * yang hanya menerima POST, jadi balasan 401/403/404/405 atas GET anonim
 * justru menandakan servernya hidup dan menjawab. Karena itu ambang "mati"
 * dipasang di 5xx, bukan di "bukan 200". Target yang memang harus
 * mengembalikan 200 bisa dipaksa lewat 'accept' di config.
 *
 * @return array{status: string, label: string, detail: string}
 */
function monitor_classify(int $errno, int $httpCode, ?float $latencyMs, array $target, array $config): array
{
    // "Terputus" hanya kalau tidak ada balasan HTTP sama sekali. Kalau kode
    // status sudah diterima, server jelas menjawab -- error yang muncul
    // setelahnya (misal body terpotong) tidak boleh dilaporkan sebagai putus,
    // karena kartu jadi menampilkan "Terputus" berdampingan dengan kode HTTP.
    if ($httpCode === 0) {
        return [
            'status' => 'down',
            'label'  => 'Terputus',
            'detail' => monitor_error_detail($errno),
        ];
    }

    $note = $errno !== 0 ? ' (transfer terputus: ' . monitor_error_detail($errno) . ')' : '';

    if ($target['accept'] !== [] && !in_array($httpCode, $target['accept'], true)) {
        return [
            'status' => 'error',
            'label'  => 'Balasan Tak Sesuai',
            'detail' => 'HTTP ' . $httpCode . ', diharapkan ' . implode('/', $target['accept']) . $note,
        ];
    }

    if ($httpCode >= 500) {
        return [
            'status' => 'error',
            'label'  => 'Error Server',
            'detail' => 'Nyambung, tapi server membalas HTTP ' . $httpCode . $note,
        ];
    }

    if ($latencyMs !== null && $latencyMs >= (float) $config['threshold_slow']) {
        return [
            'status' => 'slow',
            'label'  => 'Sangat Lambat',
            'detail' => 'Di atas ' . $config['threshold_slow'] . ' ms' . $note,
        ];
    }

    if ($latencyMs !== null && $latencyMs >= (float) $config['threshold_good']) {
        return [
            'status' => 'slow',
            'label'  => 'Lambat',
            'detail' => 'Di atas ' . $config['threshold_good'] . ' ms' . $note,
        ];
    }

    return [
        'status' => 'good',
        'label'  => 'Jaringan Bagus',
        'detail' => 'Di bawah ' . $config['threshold_good'] . ' ms' . $note,
    ];
}

/**
 * Jalankan satu gelombang probe serentak, tanpa penilaian status.
 *
 * @param list<array<string, mixed>> $targets target ber-URL (URL kosong disaring pemanggil)
 * @return array<string, array{errno: int, http_code: int, ttfb: float, connect: float}>
 */
function monitor_run_batch(array $targets, array $config): array
{
    if ($targets === []) {
        return [];
    }

    $mh      = curl_multi_init();
    $handles = [];

    foreach ($targets as $target) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $target['url'],
            // Transfer dihentikan begitu byte pertama tiba: pada titik itu kode
            // status dan waktu-sampai-jawab sudah didapat, dan sisa body tidak
            // dipakai sama sekali. Tanpa ini, mengukur satu halaman depan 31 KB
            // tiap 30 detik saja menghabiskan sekitar 3 GB per bulan dari
            // jaringan rumah sakit -- dan body yang besar bisa menabrak batas
            // waktu sehingga layanan sehat salah dilaporkan putus.
            CURLOPT_WRITEFUNCTION  => static function ($handle, $chunk): int {
                return 0; // != strlen($chunk), sehingga cURL berhenti mengunduh
            },
            CURLOPT_TIMEOUT        => (int) $config['timeout'],
            CURLOPT_CONNECTTIMEOUT => (int) $config['connect_timeout'],
            // Probe hanya mengukur keterjangkauan jaringan, tidak menukar data,
            // dan sebagian endpoint internal memakai sertifikat sendiri.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            // Redirect sengaja TIDAK diikuti. Balasan 3xx sudah membuktikan host
            // menjawab, sedangkan mengikutinya menambah mode gagal milik pihak
            // lain: vclaim-rest membalas 301 ke http:// port 80 yang diblokir,
            // sehingga endpoint sehat terbaca "terputus" dan tiap siklus
            // terbuang menunggu timeout tujuan redirect.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_USERAGENT      => (string) $config['user_agent'],
        ]);

        curl_multi_add_handle($mh, $ch);
        $handles[$target['id']] = $ch;
    }

    // Kode error transfer yang dijalankan lewat curl_multi tidak tersedia dari
    // curl_errno() pada handle-nya; ia hanya dilaporkan sekali lewat antrean
    // pesan. Tanpa dipanen di sini, semua kegagalan tampak seragam.
    $errnos = [];

    do {
        $code = curl_multi_exec($mh, $running);

        while (($message = curl_multi_info_read($mh)) !== false) {
            if ($message['msg'] === CURLMSG_DONE) {
                $errnos[monitor_handle_key($message['handle'])] = (int) $message['result'];
            }
        }

        if ($running > 0) {
            curl_multi_select($mh, 0.5);
        }
    } while ($running > 0 && $code === CURLM_OK);

    $raw = [];

    // Penghentian unduhan di atas dilaporkan cURL sebagai error tulis. Itu
    // hasil yang kita minta sendiri, jadi jangan sampai terbaca sebagai gangguan.
    $deliberate = [CURLE_WRITE_ERROR, CURLE_ABORTED_BY_CALLBACK];

    foreach ($handles as $id => $ch) {
        $info  = curl_getinfo($ch);
        $errno = $errnos[monitor_handle_key($ch)] ?? curl_errno($ch);

        if (in_array($errno, $deliberate, true) && (int) ($info['http_code'] ?? 0) > 0) {
            $errno = 0;
        }

        $raw[$id] = [
            'errno'     => $errno,
            'http_code' => (int) ($info['http_code'] ?? 0),
            // starttransfer_time = waktu sampai byte pertama. Dipakai supaya
            // angka latency mencerminkan jaringan + responsivitas server,
            // bukan besar body yang kebetulan diunduh.
            'ttfb'      => (float) ($info['starttransfer_time'] ?? 0) * 1000,
            'connect'   => (float) ($info['connect_time'] ?? 0) * 1000,
        ];

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);

    return $raw;
}

/**
 * Cek seluruh target, lengkap dengan penilaian status.
 *
 * Target yang gagal di gelombang pertama dicoba sekali lagi. Sebagian jalur
 * ke luar rumah sakit meleset beberapa persen dari waktu ke waktu; tanpa
 * percobaan ulang, kegagalan sesaat seperti itu membunyikan alarm padahal
 * layanannya sehat.
 *
 * @return list<array<string, mixed>> hasil dalam urutan yang sama dengan $targets
 */
function monitor_probe_all(array $targets, array $config): array
{
    $probeable = [];
    $blank     = [];

    foreach ($targets as $target) {
        if ($target['url'] === '') {
            $blank[$target['id']] = true;
        } else {
            $probeable[] = $target;
        }
    }

    $raw      = monitor_run_batch($probeable, $config);
    $attempts = [];
    foreach ($raw as $id => $_) {
        $attempts[$id] = 1;
    }

    if (!empty($config['retry_failed'])) {
        $failed = [];
        foreach ($probeable as $target) {
            if (($raw[$target['id']]['http_code'] ?? 0) === 0) {
                $failed[] = $target;
            }
        }

        if ($failed !== []) {
            $second = monitor_run_batch($failed, $config);
            foreach ($second as $id => $result) {
                $attempts[$id] = 2;
                // Percobaan kedua hanya menggantikan hasil kalau ia berhasil;
                // kalau sama-sama gagal, laporan pertama tetap dipakai.
                if ($result['http_code'] > 0) {
                    $raw[$id] = $result;
                }
            }
        }
    }

    $services = [];

    foreach ($targets as $target) {
        $id = $target['id'];

        if (isset($blank[$id])) {
            $services[] = [
                'id'         => $id,
                'name'       => $target['name'],
                'group'      => $target['group'],
                'status'     => 'unset',
                'label'      => 'Belum Diset',
                'detail'     => 'URL kosong di config',
                'latency_ms' => null,
                'connect_ms' => null,
                'http_code'  => null,
                'attempts'   => 0,
            ];
            continue;
        }

        $result = $raw[$id];

        // Angka waktu sah selama byte pertama sudah tiba, termasuk ketika
        // transfer gagal setelahnya -- yang diukur memang waktu sampai jawab.
        $answered  = $result['http_code'] > 0;
        $latencyMs = ($answered && $result['ttfb'] > 0) ? round($result['ttfb'], 1) : null;
        $connectMs = ($answered && $result['connect'] > 0) ? round($result['connect'], 1) : null;

        $verdict = monitor_classify($result['errno'], $result['http_code'], $latencyMs, $target, $config);

        $tries = $attempts[$id] ?? 1;
        if ($tries > 1) {
            $verdict['detail'] .= $verdict['status'] === 'down'
                ? ' — gagal pada ' . $tries . " percobaan"
                : ' — pulih pada percobaan ke-' . $tries;
        }

        $services[] = [
            'id'         => $id,
            'name'       => $target['name'],
            'group'      => $target['group'],
            'latency_ms' => $latencyMs,
            'connect_ms' => $connectMs,
            'http_code'  => $answered ? $result['http_code'] : null,
            'attempts'   => $tries,
        ] + $verdict;
    }

    return $services;
}

/**
 * Hitung ringkasan per status untuk dipajang di header.
 */
function monitor_summarize(array $services): array
{
    $summary = ['good' => 0, 'slow' => 0, 'error' => 0, 'down' => 0, 'unset' => 0];

    foreach ($services as $service) {
        $status = $service['status'];
        if (isset($summary[$status])) {
            $summary[$status]++;
        }
    }

    $summary['total']   = count($services);
    $summary['healthy'] = $summary['good'];
    $summary['problem'] = $summary['error'] + $summary['down'];

    return $summary;
}
