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
| `history_enabled` | Simpan riwayat pengecekan ke `curl/data/history.json`. |
| `history_limit` | Jumlah siklus yang disimpan. Pada refresh 30 detik, 240 siklus ≈ 2 jam. |
| `strip_length` | Berapa banyak pengecekan yang digambar pada strip riwayat tiap kartu. |
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

## Yang ditampilkan di layar

Baris paling atas adalah ringkasan keadaan. Tingginya selalu sama, berapa pun
jumlah gangguannya. Saat semua normal ia satu baris tenang yang tidak bergerak;
begitu ada gangguan, isinya berjalan kanan-ke-kiri berisi apa yang rusak, sejak
kapan, dan berapa kali terganggu belakangan ini — tapi hanya kalau isinya
memang tidak muat dalam satu baris.

Tiap kartu layanan berisi:

- **Angka besar** — waktu balas pengecekan terakhir.
- **Grafik** — pergerakan waktu balas. Garis putus-putus muncul di posisi
  ambang `threshold_good`, tapi hanya kalau memang ada nilai yang pernah
  mendekatinya; kalau tidak, garis itu cuma jadi hiasan.
- **Strip** — satu balok untuk tiap pengecekan, warnanya mengikuti status.
  Di sinilah layanan yang “kedip-kedip” langsung ketahuan, yang tidak
  terlihat dari angka sesaat maupun dari grafik.
- **Persentase** — ketersediaan selama rentang riwayat. Sengaja hanya muncul
  kalau di bawah 100%, supaya yang bermasalah tidak tenggelam di antara
  belasan angka yang sama.

Arahkan kursor ke kartu untuk melihat keterangan lengkap: waktu connect,
nilai lazim layanan itu, jumlah gangguan, dan jumlah pengecekan.

### Mode TV

Di layar lebar (mulai 1600×800) halaman otomatis masuk mode TV: tinggi dikunci
ke satu layar, tidak ada gulir, tombol kontrol dan footer disembunyikan, dan
semua kartu dibuat setinggi persis sama. Tambahkan `?tv=1` pada URL untuk
memaksanya — berguna untuk melihat tampilan TV dari laptop — atau `?tv=0` untuk
memaksa tampilan biasa.

Ukurannya dihitung dari tinggi layar, jadi mode ini benar di 4K maupun 2K tanpa
disetel ulang. Jumlah kolom kartunya satu angka di `curl/index.php`
(`$tvCols`, default 4).

Karena tombolnya hilang di mode TV, alarm dinyalakan dengan menekan **`A`** pada
keyboard. Tombol itu juga yang membuat browser mengizinkan suara keluar: tanpa
satu gestur pengguna, alarm akan diam saja meski statusnya tampak aktif.

Di bawah 1600×800 tampilannya seperti biasa: bisa digulir, tombol lengkap, dan
kerapatannya menyesuaikan lebar layar.

### Soal angka ketersediaan

Pengecekan hanya berjalan selama ada browser yang membuka dashboard ini.
Jadi persentasenya berarti “dari pengecekan yang sempat dilakukan”, bukan
uptime 24 jam. Kalau dashboard ditutup semalaman, malam itu tidak terhitung.
Untuk pemantauan menerus, biarkan satu layar membukanya sepanjang waktu.
