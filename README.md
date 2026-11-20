# SewaMobil — Website Rental Kendaraan

PHP (mysqli) + MySQL/MariaDB + HTML/JS. Dijalankan lewat web server (XAMPP/Laragon), **bukan** dibuka langsung dari file (`file://`).

## Menjalankan (XAMPP)
1. Salin folder ini ke `C:\xampp\htdocs\Rental_kendaraan`.
2. Start **Apache** dan **MySQL** di XAMPP Control Panel.
3. phpMyAdmin → tab **Import** → pilih `database/rental_mobil.sql` → Go.
   (Berkas ini membuat database `rental_mobil`, semua tabel, dan data awal.)
4. Cek `api/config.php` (default: host `localhost`, user `root`, password kosong).
5. Buka `http://localhost/Rental_kendaraan/`.

Folder `uploads/mobil/` harus bisa ditulis oleh web server (foto mobil disimpan di sana).

## Akun demo (password semua: `123456`)
| Role  | Email            | Masuk ke                |
|-------|------------------|-------------------------|
| User  | andi@mail.com    | `user/katalog.html`     |
| Mitra | mitra@mail.com   | `mitra/dashboard.html`  |
| Admin | admin@mail.com   | `admin/dashboard.html`  |

## Alur yang sudah berjalan
Login/logout (3 role) → katalog (+filter `?jenis=`) → detail → checkout → pembayaran (simulasi VA) → struk
Mitra: lihat / tambah / edit / nonaktifkan mobil (+ upload foto)
Admin: laporan pendapatan per rentang tanggal (grafik Chart.js, butuh internet untuk CDN)

## Aturan bisnis di server (`api/booking.php`)
- Harga & deposit dihitung ulang di server dari data mobil (deposit lepas kunci = kolom `mobil.deposit`; dengan supir = 0).
- Mobil tidak bisa dipesan di tanggal yang bentrok. Booking `pending` menahan mobil selama 1 jam.
- Tanggal mulai tidak boleh di masa lalu.

## Belum dikerjakan
`register.html`, `user/dashboard.html`, `user/riwayat.html`, `mitra/{booking-masuk,pendapatan,profil}.html`,
`admin/*` selain dashboard, serta `api/{auth,user,mitra}.php` (masih kosong, sengaja dibiarkan).
`mitra/dashboard.html` dan tabel/kartu statistik di `admin/dashboard.html` masih data contoh (statis).
`pembayaran.php` adalah SIMULASI — untuk gateway sungguhan, `callback` harus diverifikasi signature dari gateway.

## Troubleshooting login

1. Pastikan Apache dan MySQL berjalan di XAMPP/Laragon.
2. Buka `http://localhost/Rental_kendaraan/login.html` (jangan buka file HTML langsung).
3. Pastikan database `rental_mobil` sudah diimpor dan tabel `users` tersedia.
4. Jika akun demo gagal, jalankan `database/reset_akun_demo.sql` melalui phpMyAdmin. Script ini hanya mereset password dan mengaktifkan akun demo, tidak menghapus data lain.
5. Akun demo: `admin@mail.com`, `mitra@mail.com`, `andi@mail.com`; password `123456`.
6. Jika masih gagal, cek Apache `error.log` dan pastikan PHP extension `mysqli` aktif.

## Catatan DOKU

Pembayaran saat ini masih simulasi. Integrasi DOKU Checkout membutuhkan Client ID dan Secret Key dari DOKU Sandbox. Jangan menaruh Secret Key di HTML/JavaScript; hanya konfigurasi server PHP yang boleh mengaksesnya. Setelah akun Sandbox tersedia, lanjutkan endpoint create checkout, redirect ke payment URL, webhook notification yang memverifikasi signature, dan update status transaksi secara idempotent.
