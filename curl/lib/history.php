<?php

declare(strict_types=1);

/**
 * Riwayat pengecekan yang disimpan di sisi server.
 *
 * Tanpa ini, riwayat hanya hidup di browser masing-masing: dibuka dari PC lain
 * grafiknya kosong, dan sekali cache dibersihkan semua jejak gangguan hilang.
 * Satu berkas JSON bergulir sudah cukup untuk kebutuhan sebesar ini -- belasan
 * layanan, satu sampel tiap setengah menit.
 *
 * Catatan jujur soal angka ketersediaan: pengecekan hanya berjalan selama ada
 * browser yang membuka dashboard. Jadi persentasenya adalah "dari pengecekan
 * yang sempat dilakukan", bukan uptime sesungguhnya 24 jam. Itu sebabnya
 * jumlah sampel ikut dilaporkan, supaya angkanya bisa dinilai apa adanya.
 */

function history_status_char(string $status): string
{
    switch ($status) {
        case 'good':
            return 'g';
        case 'slow':
            return 's';
        case 'error':
            return 'e';
        case 'down':
            return 'd';
        default:
            return 'u';
    }
}

function history_char_status(string $char): string
{
    switch ($char) {
        case 'g':
            return 'good';
        case 's':
            return 'slow';
        case 'e':
            return 'error';
        case 'd':
            return 'down';
        default:
            return 'unset';
    }
}

/**
 * Layanan dianggap terpakai kalau ia menjawab dalam batas waktu, termasuk saat
 * lambat. Balasan 5xx tidak dihitung tersedia: servernya hidup, tapi layanannya
 * tidak bisa dipakai.
 */
function history_is_available(string $status): bool
{
    return $status === 'good' || $status === 'slow';
}

function history_file(string $baseDir): string
{
    return rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . 'history.json';
}

/**
 * Siapkan folder penyimpanan. Kembalikan null kalau siap, atau alasan gagalnya.
 *
 * Alasannya perlu dibedakan, bukan disatukan jadi "tidak bisa ditulis": folder
 * yang belum ada dan folder yang ada tapi tertutup hak tulis butuh tindakan
 * yang berbeda, dan yang membaca pesan ini biasanya sedang berdiri di depan
 * layar tanpa akses shell ke servernya.
 *
 * Kegagalan di sini tidak boleh menjatuhkan dashboard: pemantauan tetap jalan,
 * hanya riwayatnya yang tidak tersimpan.
 */
function history_prepare_dir(string $dir): ?string
{
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return 'Folder curl/data belum ada dan PHP tidak bisa membuatnya sendiri.';
        }
    }

    if (!is_writable($dir)) {
        return 'Folder curl/data ada, tapi akun yang menjalankan Apache tidak '
            . 'punya hak tulis ke sana.';
    }

    return null;
}

/**
 * Baca, tambahkan satu sampel, lalu tulis kembali dalam satu kali kunci berkas.
 *
 * Beberapa browser bisa memanggil API bersamaan. Kuncinya mencegah dua penulis
 * saling menimpa, dan jeda minimum mencegah satu siklus tercatat berkali-kali
 * hanya karena dashboard dibuka di beberapa layar sekaligus.
 *
 * @param list<array<string, mixed>> $services hasil monitor_probe_all()
 * @return list<array<string, mixed>> seluruh sampel setelah penambahan
 */
function history_append(string $file, array $services, array $config): array
{
    $limit     = max(10, (int) ($config['history_limit'] ?? 240));
    $minGap    = max(5, (int) round(((int) $config['refresh_interval']) * 0.6));
    $now       = time();

    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        return [];
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return [];
        }

        $raw     = stream_get_contents($handle);
        $stored  = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        $samples = (is_array($stored) && isset($stored['samples']) && is_array($stored['samples']))
            ? $stored['samples']
            : [];

        $last = $samples === [] ? null : $samples[count($samples) - 1];

        if ($last !== null && ($now - (int) ($last['t'] ?? 0)) < $minGap) {
            // Siklus ini sudah terwakili sampel terakhir; jangan catat dobel.
            return $samples;
        }

        $entry = ['t' => $now, 'd' => []];
        foreach ($services as $service) {
            $entry['d'][$service['id']] = [
                history_status_char((string) $service['status']),
                $service['latency_ms'] === null ? null : (int) round((float) $service['latency_ms']),
            ];
        }

        $samples[] = $entry;
        if (count($samples) > $limit) {
            $samples = array_slice($samples, -$limit);
        }

        $payload = json_encode(
            ['v' => 1, 'samples' => $samples],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($payload !== false) {
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $payload);
            fflush($handle);
        }

        return $samples;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Ringkas riwayat menjadi angka per layanan.
 *
 * @param list<array<string, mixed>> $samples
 * @param list<array<string, mixed>> $services
 * @return array<string, array<string, mixed>>
 */
function history_stats(array $samples, array $services, int $stripLength): array
{
    $stats = [];

    foreach ($services as $service) {
        $id = $service['id'];

        $strip      = [];
        $latencies  = [];
        $tersedia   = 0;
        $terhitung  = 0;
        $gangguan   = 0;
        $sejak      = null;
        $sebelumnya = null;

        foreach ($samples as $sample) {
            if (!isset($sample['d'][$id])) {
                continue;
            }

            $point   = $sample['d'][$id];
            $status  = history_char_status((string) ($point[0] ?? 'u'));
            $latency = $point[1] ?? null;

            $strip[]     = ['t' => (int) ($sample['t'] ?? 0), 's' => $status];
            $latencies[] = $latency === null ? null : (int) $latency;

            if ($status !== 'unset') {
                $terhitung++;
                if (history_is_available($status)) {
                    $tersedia++;
                }

                // Satu gangguan dihitung saat status berpindah dari tersedia ke
                // tidak, bukan tiap sampel -- supaya gangguan 10 menit tidak
                // terbaca sebagai 20 kejadian terpisah.
                $adalahGangguan = !history_is_available($status);
                $sebelumnyaOke  = $sebelumnya === null || history_is_available($sebelumnya);
                if ($adalahGangguan && $sebelumnyaOke) {
                    $gangguan++;
                }
            }

            if ($sebelumnya === null || $status !== $sebelumnya) {
                $sejak = (int) ($sample['t'] ?? 0);
            }

            $sebelumnya = $status;
        }

        // Selisih dihitung terhadap nilai lazim layanan ini (median riwayat),
        // bukan terhadap satu pengecekan sebelumnya. Latency ke luar rumah sakit
        // wajar bergoyang ratusan milidetik antar pengecekan, jadi pembanding
        // "sebelumnya" membuat penanda menyala hampir selalu dan berhenti
        // berarti apa-apa. Median hanya bergerak kalau kebiasaannya berubah.
        $delta    = null;
        $baseline = null;
        $angka    = array_values(array_filter($latencies, static function ($v) {
            return $v !== null;
        }));

        if (count($angka) >= 5 && $service['latency_ms'] !== null) {
            sort($angka);
            $jumlah   = count($angka);
            $baseline = $jumlah % 2 === 1
                ? $angka[intdiv($jumlah, 2)]
                : (int) round(($angka[intdiv($jumlah, 2) - 1] + $angka[intdiv($jumlah, 2)]) / 2);
            $delta    = (int) round((float) $service['latency_ms']) - $baseline;
        }

        $stats[$id] = [
            'availability' => $terhitung > 0 ? round($tersedia / $terhitung * 100, 1) : null,
            'samples'      => $terhitung,
            'outages'      => $gangguan,
            'since'        => $sejak,
            'delta_ms'     => $delta,
            'baseline_ms'  => $baseline,
            'strip'        => array_slice($strip, -$stripLength),
            'latencies'    => array_slice($latencies, -$stripLength),
        ];
    }

    return $stats;
}

/**
 * Daftar layanan yang sedang bermasalah, diurutkan dari yang paling parah.
 *
 * @return list<array<string, mixed>>
 */
function history_incidents(array $services, array $stats): array
{
    $bobot = ['down' => 0, 'error' => 1, 'slow' => 2];
    $daftar = [];

    foreach ($services as $service) {
        if (!isset($bobot[$service['status']])) {
            continue;
        }

        $stat = $stats[$service['id']] ?? [];

        $daftar[] = [
            'id'       => $service['id'],
            'name'     => $service['name'],
            'group'    => $service['group'],
            'status'   => $service['status'],
            'label'    => $service['label'],
            'detail'   => $service['detail'],
            'since'    => $stat['since'] ?? null,
            'outages'  => $stat['outages'] ?? 0,
            'latency_ms' => $service['latency_ms'],
        ];
    }

    usort($daftar, static function (array $a, array $b) use ($bobot): int {
        return $bobot[$a['status']] <=> $bobot[$b['status']];
    });

    return $daftar;
}
