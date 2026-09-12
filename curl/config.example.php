<?php

/**
 * Template konfigurasi Respon WS.
 *
 * Salin file ini menjadi config.php di tiap server, lalu isi URL yang sebenarnya.
 * config.php sengaja di-gitignore supaya endpoint internal rumah sakit
 * tidak ikut ter-commit.
 *
 * Tiap layanan boleh ditulis dua cara:
 *
 *   'Nama Layanan' => 'https://contoh.id/endpoint',
 *
 *   'Nama Layanan' => [
 *       'url'    => 'https://contoh.id/endpoint',
 *       'accept' => [200],   // opsional: HTTP code yang dianggap sehat
 *   ],
 *
 * Tanpa 'accept', semua balasan HTTP di bawah 500 dianggap "server hidup".
 * Itu memang yang diinginkan untuk API ber-autentikasi seperti BPJS dan
 * Satu Sehat, yang membalas 401/403/404 atas GET anonim. Pakai 'accept'
 * hanya untuk halaman yang benar-benar harus membalas 200.
 */

return [
    'title'    => 'Respon WS',
    'subtitle' => 'Nama Rumah Sakit',
    'credit'   => 'Tim IT',

    // Detik antar refresh otomatis di browser.
    'refresh_interval' => 30,

    // Batas waktu tiap probe, dalam detik. Karena probe berjalan serentak,
    // satu siklus tidak akan lebih lama dari nilai ini.
    'timeout'         => 3,
    'connect_timeout' => 3,

    // Ambang penilaian latency (milidetik, diukur sampai byte pertama).
    'threshold_good' => 500,   // di bawah ini: Jaringan Bagus
    'threshold_slow' => 1500,  // di atas ini: Sangat Lambat

    // Coba ulang sekali target yang gagal terhubung. Sebagian jalur ke luar
    // rumah sakit meleset beberapa persen dari waktu ke waktu; tanpa ini,
    // kegagalan sesaat membunyikan alarm padahal layanannya sehat.
    // Konsekuensinya: saat benar-benar ada yang mati, siklus memakan
    // waktu hingga dua kali timeout.
    'retry_failed' => true,

    // Nyalakan hanya saat menelusuri masalah. Saat true, pesan error PHP
    // ikut tampil di halaman dan di respons API.
    'debug' => false,

    'groups' => [
        'BPJS Kesehatan' => [
            'Update Waktu Antrean' => 'https://apijkn.bpjs-kesehatan.go.id/antreanrs/antrean/updatewaktu',
            'Add Antrean'          => 'https://apijkn.bpjs-kesehatan.go.id/antreanrs/antrean/add',
            'Batal Antrean'        => 'https://apijkn.bpjs-kesehatan.go.id/antreanrs/antrean/batal',
            'Add Farmasi'          => 'https://apijkn.bpjs-kesehatan.go.id/antreanrs/antrean/farmasi/add',
            'Finger Print'         => 'https://fp.bpjs-kesehatan.go.id/finger-rest',
            'Vclaim Rest'          => 'https://apijkn.bpjs-kesehatan.go.id/vclaim-rest',
            'Aplicare'             => 'https://new-api.bpjs-kesehatan.go.id/aplicaresws',
            'I-Care'               => 'https://apijkn.bpjs-kesehatan.go.id/wsihs/api/rs',
        ],

        'Kemenkes & Dinkes' => [
            'Auth Satu Sehat' => 'https://api-satusehat.kemkes.go.id/oauth2/v1',
            'FHIR Satu Sehat' => 'https://api-satusehat.kemkes.go.id/fhir-r4/v1',
            'Patient Journey' => 'https://api-dinkes.jakarta.go.id/patientjourney/api/v1',
        ],

        'Internal & Jaringan' => [
            // Pembanding: kalau ini ikut merah, masalahnya di jalur internet,
            // bukan di masing-masing penyedia layanan.
            'Jaringan Internet' => [
                'url'    => 'https://www.google.com/',
                'accept' => [200],
            ],

            // Ganti dengan URL dashboard internal rumah sakit Anda.
            'Manajemen Bed' => [
                'url'    => 'https://contoh-internal.example.id/dashboardbed/',
                'accept' => [200],
            ],
        ],
    ],
];
