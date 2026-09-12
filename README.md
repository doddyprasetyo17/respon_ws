# Respon WS

Dashboard pemantau koneksi jaringan rumah sakit ke web service yang dipakai
sehari-hari: BPJS Kesehatan (antrean, VClaim, Aplicare, I-Care, finger print),
Satu Sehat (Kemenkes), dan Dinas Kesehatan DKI Jakarta.

Halaman menampilkan satu kartu per layanan berisi status, waktu balas, dan
grafik riwayat singkat. Kalau ada layanan yang tidak menjawab, kartunya merah
dan alarm bisa dibunyikan.

## Menjalankan

Aplikasi PHP biasa, tanpa dependensi dan tanpa proses build. Taruh di `htdocs`
XAMPP lalu buka `http://localhost/respon_ws/`.

Yang dibutuhkan: PHP 8.0+ dengan ekstensi `curl` dan `openssl` aktif.

## Konfigurasi

Daftar URL dan setelan ambang batas tidak ikut ter-commit, supaya endpoint
internal rumah sakit tidak tersebar. Sebelum dipakai pertama kali:

```
copy curl\config.example.php curl\config.php
```

Lalu sesuaikan isinya. Kalau `config.php` belum ada, halaman akan menampilkan
petunjuk ini, bukan error.

Isi yang paling sering diubah ada di `curl/config.php`:

| Setelan | Arti |
| --- | --- |
| `groups` | Daftar layanan, dikelompokkan per penyedia. Nama key jadi judul kartu. |
| `refresh_interval` | Jarak antar pengecekan otomatis, dalam detik. |
| `timeout` | Batas tunggu tiap probe, dalam detik. |
| `threshold_good` | Di bawah nilai ini (ms) status dianggap bagus. |
| `threshold_slow` | Di atas nilai ini (ms) status jadi "sangat lambat". |
| `retry_failed` | Ulangi sekali target yang gagal, supaya gangguan sesaat tidak membunyikan alarm. |
| `debug` | Tampilkan pesan error PHP. Biarkan `false` saat dipakai sehari-hari. |

Menambah layanan cukup menambah satu baris di `groups`:

```php
'Nama Layanan' => 'https://alamat-endpoint',
```

## Cara status ditentukan

Endpoint BPJS dan Satu Sehat adalah API ber-autentikasi yang hanya menerima
POST, jadi balasan `401`, `403`, `404`, atau `405` atas pengecekan anonim
justru menandakan servernya hidup. Karena itu:

| Kondisi | Status |
| --- | --- |
| Ada balasan HTTP di bawah 500 | Dinilai dari kecepatannya: **Jaringan Bagus** / **Lambat** / **Sangat Lambat** |
| Balasan HTTP 5xx | **Error Server** — nyambung, tapi servernya bermasalah |
| Tidak ada balasan sama sekali | **Terputus** — timeout, DNS gagal, atau koneksi ditolak |

Kalau sebuah alamat memang harus membalas `200` (misalnya halaman dashboard
internal), pakai bentuk panjang di config:

```php
'Manajemen Bed' => [
    'url'    => 'https://alamat-dashboard/',
    'accept' => [200],
],
```

Waktu yang ditampilkan adalah **waktu sampai byte pertama (TTFB)**, bukan waktu
unduh seluruh halaman, supaya angkanya mencerminkan kondisi jaringan dan bukan
besar halaman yang kebetulan diminta.
