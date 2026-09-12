<?php

declare(strict_types=1);

/**
 * Kerangka halaman dashboard.
 *
 * Halaman ini tidak menjalankan probe apa pun. Ia hanya merender satu kartu
 * kosong per layanan dari config, lalu app.js mengisinya dari api.php.
 */

require __DIR__ . '/lib/monitor.php';

date_default_timezone_set('Asia/Jakarta');

$configError = null;
$config      = monitor_defaults();
$targets     = [];

try {
    $config  = monitor_load_config(__DIR__ . '/config.php');
    $targets = monitor_flatten_groups($config['groups']);
} catch (Throwable $e) {
    $configError = $e->getMessage();
}

error_reporting($config['debug'] ? E_ALL : 0);
ini_set('display_errors', $config['debug'] ? '1' : '0');

/** Kelompokkan ulang target per grup untuk kebutuhan render. */
$byGroup = [];
foreach ($targets as $target) {
    $byGroup[$target['group']][] = $target;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Jumlah kolom kartu pada mode TV. Satu-satunya tempat angka ini ditulis;
 * ganti ke 3 atau 5 kalau kartunya mau lebih besar atau lebih rapat.
 */
$tvCols = 4;

/**
 * Tinggi baris papan untuk mode TV.
 *
 * Mode TV mengunci tinggi halaman ke satu layar, jadi papan tidak boleh
 * memakai tinggi sesuai isi. Semua kartu harus setinggi persis sama meski
 * grupnya berbeda jumlah, dan itu hanya bisa dipastikan kalau baris judul
 * grup ('auto') dipisahkan dari baris kartu ('1fr') di template grid.
 * Dihitung dari config, sehingga menambah layanan tidak perlu menyentuh CSS.
 */
$rowTracks = [];
foreach ($byGroup as $services) {
    $rowTracks[] = 'auto';
    $rows = (int) ceil(count($services) / $tvCols);
    for ($i = 0; $i < max(1, $rows); $i++) {
        $rowTracks[] = '1fr';
    }
}
$tvRows = implode(' ', $rowTracks);

$assetVersion = '4.0';

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title><?= e($config['title']) ?><?= $config['subtitle'] !== '' ? ' · ' . e($config['subtitle']) : '' ?></title>

    <!-- Pemilihan mode tampilan. Dijalankan di <head>, sebelum halaman dilukis,
         supaya tidak ada kedip dari layout laptop ke layout TV.

         Deteksi memakai tinggi viewport, bukan lebar dalam piksel perangkat:
         TV 4K di Windows dengan scaling 150% melaporkan lebar CSS 2560px, bukan
         3840px, jadi ambang berbasis "3840" justru tidak pernah kena.
         ?tv=1 memaksa mode TV (untuk dites dari laptop), ?tv=0 memaksa mode biasa. -->
    <script>
        (function () {
            var paksa = null;
            var cocok = /[?&]tv=([01])/.exec(window.location.search);
            if (cocok) {
                paksa = cocok[1] === '1';
            }
            var tv = paksa !== null
                ? paksa
                : (window.innerWidth >= 1600 && window.innerHeight >= 800);
            document.documentElement.setAttribute('data-mode', tv ? 'tv' : 'desk');
        }());
    </script>

    <link rel="stylesheet" href="assets/app.css?v=<?= e($assetVersion) ?>">
</head>

<body>

<?php if ($configError !== null): ?>

    <main class="setup" role="main">
        <h1 class="setup__title">Konfigurasi belum siap</h1>
        <p class="setup__message"><?= e($configError) ?></p>
        <pre class="setup__code">cd <?= e(dirname(__DIR__)) ?>
copy curl\config.example.php curl\config.php</pre>
        <p class="setup__hint">Setelah file dibuat, sesuaikan daftar URL di dalamnya lalu muat ulang halaman ini.</p>
    </main>

<?php else: ?>

    <div class="sweep" id="sweep" aria-hidden="true"><span class="sweep__fill" id="sweepFill"></span></div>

    <header class="topbar">
        <div class="topbar__identity">
            <h1 class="topbar__title"><?= e($config['title']) ?></h1>
            <?php if ($config['subtitle'] !== ''): ?>
                <p class="topbar__subtitle"><?= e($config['subtitle']) ?></p>
            <?php endif; ?>
        </div>

        <div class="vitals" id="vitals" role="status" aria-live="polite" aria-label="Ringkasan status layanan">
            <div class="vital vital--good">
                <span class="vital__count" data-summary="good">–</span>
                <span class="vital__label">Bagus</span>
            </div>
            <div class="vital vital--slow">
                <span class="vital__count" data-summary="slow">–</span>
                <span class="vital__label">Lambat</span>
            </div>
            <div class="vital vital--error">
                <span class="vital__count" data-summary="error">–</span>
                <span class="vital__label">Error</span>
            </div>
            <div class="vital vital--down">
                <span class="vital__count" data-summary="down">–</span>
                <span class="vital__label">Putus</span>
            </div>
        </div>

        <!-- Jam sengaja berada di luar .controls: mode TV menyembunyikan kontrol,
             dan jam "Diperbarui" justru penanda paling penting di sana -- ia yang
             memberi tahu apakah angka di layar masih segar atau sudah membeku. -->
        <p class="freshness">
            <span class="freshness__time" id="cycleNote">--:--:--</span>
            <span class="freshness__label">Diperbarui</span>
        </p>

        <div class="controls">
            <button type="button" class="btn" id="refreshBtn">
                Cek sekarang
                <span class="btn__countdown" id="countdown"></span>
            </button>
            <!-- Di mode TV tombol ini menyusut jadi ikon saja; yang mengubah
                 statusnya di sana adalah tombol "A" pada keyboard, karena
                 kebijakan autoplay browser tetap menuntut satu gestur pengguna
                 sebelum alarm boleh berbunyi. -->
            <button type="button" class="btn btn--toggle" id="alarmBtn" aria-pressed="false"
                    title="Alarm saat layanan terputus (tombol A)">
                <span class="btn__icon" aria-hidden="true">🔔</span>
                <span id="alarmLabel">Alarm mati</span>
            </button>
        </div>
    </header>

    <div class="banner" id="banner" role="alert" hidden></div>

    <noscript>
        <div class="banner banner--static">
            Dashboard ini butuh JavaScript aktif untuk mengambil data pengecekan.
        </div>
    </noscript>

    <!-- Baris keadaan: tingginya tetap, apa pun jumlah gangguannya. Saat semua
         normal ia satu baris diam; begitu ada yang bermasalah, isinya berjalan
         kanan-ke-kiri supaya semua gangguan kebaca bergantian tanpa pernah
         menambah tinggi halaman. Tinggi yang berubah-ubah adalah hal yang
         merusak layout satu-layar, justru pada saat kartu paling perlu terlihat.

         Dua segmen dengan isi identik: animasi menggeser separuh lebar,
         sehingga segmen kedua persis menggantikan yang pertama dan putarannya
         tidak pernah menyisakan ruang kosong. -->
    <section class="ticker" id="ticker" data-state="pending" aria-live="polite">
        <span class="ticker__dot" aria-hidden="true"></span>
        <div class="ticker__viewport">
            <div class="ticker__run" id="tickerRun">
                <span class="ticker__seg" id="tickerSeg">Memeriksa layanan…</span>
                <span class="ticker__seg" id="tickerSegClone" aria-hidden="true"></span>
            </div>
        </div>
    </section>

    <main class="board" id="board"
          style="--cols: <?= (int) $tvCols ?>; grid-template-rows: <?= e($tvRows) ?>;"
          data-refresh="<?= (int) $config['refresh_interval'] ?>"
          data-good="<?= (int) $config['threshold_good'] ?>"
          data-slow="<?= (int) $config['threshold_slow'] ?>">

        <?php foreach ($byGroup as $groupName => $services): ?>
            <section class="group">
                <h2 class="group__name">
                    <?= e($groupName) ?>
                    <span class="group__count"><?= count($services) ?></span>
                </h2>

                <div class="grid">
                    <?php foreach ($services as $service): ?>
                        <article class="card" data-svc="<?= e($service['id']) ?>" data-status="pending">
                            <div class="card__head">
                                <span class="dot" aria-hidden="true"></span>
                                <h3 class="card__name"><?= e($service['name']) ?></h3>
                                <span class="card__avail" data-field="avail"></span>
                            </div>

                            <p class="card__reading">
                                <span class="card__value" data-field="latency">–––</span>
                                <span class="card__unit">ms</span>
                            </p>

                            <svg class="trace" viewBox="0 0 200 40" preserveAspectRatio="none" aria-hidden="true">
                                <line class="trace__threshold" data-field="threshold"
                                      x1="0" x2="200" y1="20" y2="20" opacity="0" />
                                <polygon class="trace__area" data-field="area" points="" />
                                <polyline class="trace__line" data-field="trace" points="0,20 200,20" />
                            </svg>

                            <div class="strip" data-field="strip" role="img"
                                 aria-label="Riwayat pengecekan terakhir"></div>

                            <p class="card__meta">
                                <span class="card__label" data-field="label">Menunggu…</span>
                                <span class="card__code" data-field="code"></span>
                            </p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </main>

    <footer class="footer">
        <span>&copy; <?= date('Y') ?><?= $config['credit'] !== '' ? ' ' . e($config['credit']) : '' ?></span>
        <span class="footer__sep">·</span>
        <span>Latency diukur sampai byte pertama (TTFB)</span>
        <span class="footer__sep">·</span>
        <span id="windowNote">–</span>
        <span class="footer__sep">·</span>
        <span id="cycleDuration">–</span>
    </footer>

    <script src="assets/app.js?v=<?= e($assetVersion) ?>" defer></script>

<?php endif; ?>

</body>

</html>
