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

$assetVersion = '2.3';

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title><?= e($config['title']) ?><?= $config['subtitle'] !== '' ? ' · ' . e($config['subtitle']) : '' ?></title>
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

        <div class="controls">
            <p class="freshness">
                <span class="freshness__time" id="cycleNote">--:--:--</span>
                <span class="freshness__label">Diperbarui</span>
            </p>
            <button type="button" class="btn" id="refreshBtn">
                Cek sekarang
                <span class="btn__countdown" id="countdown"></span>
            </button>
            <button type="button" class="btn btn--toggle" id="alarmBtn" aria-pressed="false">
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

    <main class="board" id="board"
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
                            </div>

                            <p class="card__reading">
                                <span class="card__value" data-field="latency">–––</span>
                                <span class="card__unit">ms</span>
                            </p>

                            <svg class="trace" viewBox="0 0 200 40" preserveAspectRatio="none" aria-hidden="true">
                                <polyline class="trace__line" data-field="trace" points="0,20 200,20" />
                            </svg>

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
        <span id="cycleDuration">–</span>
    </footer>

    <script src="assets/app.js?v=<?= e($assetVersion) ?>" defer></script>

<?php endif; ?>

</body>

</html>
