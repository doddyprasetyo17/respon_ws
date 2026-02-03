<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Jakarta');

function secure_encrypt($plaintext, $secret_key)
{
    $key = hash('sha256', $secret_key, true); // 32 byte
    $iv  = random_bytes(16); // AES block size

    $ciphertext = openssl_encrypt(
        $plaintext,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    // HMAC untuk anti-tamper
    $hmac = hash_hmac('sha256', $ciphertext, $key, true);

    // Gabung: iv + hmac + ciphertext
    return base64_encode($iv . $hmac . $ciphertext);
}

function secure_decrypt($encrypted, $secret_key)
{
    $data = base64_decode($encrypted);
    if ($data === false || strlen($data) < 48) {
        return false;
    }

    $key = hash('sha256', $secret_key, true);

    $iv          = substr($data, 0, 16);
    $hmac        = substr($data, 16, 32);
    $ciphertext  = substr($data, 48);

    // Validasi HMAC
    $calc_hmac = hash_hmac('sha256', $ciphertext, $key, true);
    if (!hash_equals($hmac, $calc_hmac)) {
        return false; // data diubah
    }

    return openssl_decrypt(
        $ciphertext,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );
}


/* ================= URL LIST ================= */
$urls = [
    'Update Waktu BPJS' => 'https://apijkn.bpjs-kesehatan.go.id/antreanrs/antrean/updatewaktu',
    'Add Antrean BPJS' => 'https://apijkn.bpjs-kesehatan.go.id/antreanrs/antrean/add',
    'Batal Antrean BPJS' => 'https://apijkn.bpjs-kesehatan.go.id/antreanrs/antrean/batal',
    'Add Farmasi BPJS' => 'https://apijkn.bpjs-kesehatan.go.id/antreanrs/antrean/farmasi/add',
    'Finger BPJS' => 'https://fp.bpjs-kesehatan.go.id/finger-rest',
    'Vclaim Rest BPJS' => 'https://apijkn.bpjs-kesehatan.go.id/vclaim-rest',
    'Aplicare BPJS' => 'https://new-api.bpjs-kesehatan.go.id/aplicaresws',
    'I-Care BPJS' => 'https://apijkn.bpjs-kesehatan.go.id/wsihs/api/rs',
    'Jaringan RS' => 'https://www.google.com/',
    'Manajemen Bed RS' => 'https://rsudjoharbaru.jakarta.go.id/dashboardbed/',
    'Auth Satu Sehat' => 'https://api-satusehat.kemkes.go.id/oauth2/v1',
    'FHIR Satu Sehat' => 'https://api-satusehat.kemkes.go.id/fhir-r4/v1',
    'Patient Journey Dinas Kesehatan' => 'https://api-dinkes.jakarta.go.id/patientjourney/api/v1',
];

/* ================= CEK URL ================= */
function getUrlInfo($url)
{
    if (trim($url) === '') {
        return [
            'latency' => 0,
            'status' => 'Belum Diset',
            'color' => 'gray'
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $start = microtime(true);
    $response = curl_exec($ch);
    $time = round((microtime(true) - $start) * 1000, 2);

    if ($response === false) {
        curl_close($ch);
        return [
            'latency' => 0,
            'status' => 'Terputus',
            'color' => 'red'
        ];
    }

    curl_close($ch);

    return [
        'latency' => $time,
        'status' => ($time < 500 ? 'Jaringan Bagus' : 'Lambat'),
        'color' => ($time < 500 ? 'green' : 'orange')
    ];
}

/* ================= PROSES DATA ================= */
$url_infos = [];

foreach ($urls as $name => $url) {
    $url_infos[$name] = getUrlInfo($url);
}
/* ================= SECURE FOOTER ================= */

$footer_secret_key = 'my_super_secret_key_CHANGE_THIS';

// Isi footer asli
$footer_plain = date("Y") . " Prazz. All rights reserved.";

// Enkripsi (sekali per request)
$footer_encrypted = secure_encrypt($footer_plain, $footer_secret_key);

// Dekripsi + validasi
$footer_decrypted = secure_decrypt($footer_encrypted, $footer_secret_key);

// Valid flag
$footer_valid = ($footer_decrypted === $footer_plain);


?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Response Time Server WS</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>

<body>

    <div class="container mt-4">
        <h3>Response Time Server WS</h3>

        <div class="d-flex align-items-center mb-3">
            <button class="btn btn-primary mr-3" onclick="manualRefresh()">
                🔄 Refresh Sekarang
            </button>
            <div>
                ⏳ Refresh otomatis dalam
                <b><span id="countdown">120</span></b> detik
                <span id="pauseInfo" class="text-warning ml-2" style="display:none;">
                    (Paused)
                </span>
            </div>
        </div>
        <div class="row">
            <?php foreach ($url_infos as $name => $info): ?>
                <div class="col-md-4">
                    <div class="border rounded p-3 mb-3"
                        data-status="<?= $info['status'] ?>"
                        style="border-color:<?= $info['color'] ?>">
                        <h5><?= $name ?></h5>
                        <p>Status: <b style="color:<?= $info['color'] ?>"><?= $info['status'] ?></b></p>
                        <p>Latency: <?= $info['latency'] ?> ms</p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>
    <footer class="text-center mt-5 p-3"
        style="background:#212529;color:#fff;font-size:13px;">

        <?php if (!$footer_valid): ?>
            <span class="text-danger">pras@rsudjb2025</span>
        <?php else: ?>
            <?= htmlspecialchars($footer_decrypted, ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>

    </footer>
    <script>
        const REFRESH_INTERVAL = 30; // detik (2 menit)
        let remaining = REFRESH_INTERVAL;
        let timer = null;
        let isPaused = false;

        function updateCountdown() {
            if (isPaused) return;

            remaining--;
            if (remaining <= 0) {
                clearInterval(timer);
                location.reload();
                return;
            }

            document.getElementById('countdown').innerText = remaining;
        }

        function startCountdown() {
            clearInterval(timer); // 🔒 anti double timer
            remaining = REFRESH_INTERVAL;
            document.getElementById('countdown').innerText = remaining;

            timer = setInterval(updateCountdown, 1000);
        }

        function manualRefresh() {
            location.reload();
        }

        // Auto pause kalau tab tidak aktif
        document.addEventListener("visibilitychange", function() {
            isPaused = document.hidden;
            document.getElementById('pauseInfo').style.display =
                document.hidden ? 'inline' : 'none';
        });

        startCountdown();
    </script>

    <audio id="alarmSound" loop>
        <source src="https://actions.google.com/sounds/v1/alarms/alarm_clock.ogg" type="audio/ogg">
    </audio>
    <script>
        const alarm = document.getElementById('alarmSound');
        let alarmPlaying = false;

        function checkAlarm() {
            const statuses = document.querySelectorAll('[data-status]');
            let hasDown = false;

            statuses.forEach(el => {
                if (el.dataset.status === 'Terputus') {
                    hasDown = true;
                }
            });

            if (hasDown && !alarmPlaying) {
                alarm.play();
                alarmPlaying = true;
            }

            if (!hasDown && alarmPlaying) {
                alarm.pause();
                alarm.currentTime = 0;
                alarmPlaying = false;
            }
        }

        checkAlarm();
    </script>


</body>

</html>