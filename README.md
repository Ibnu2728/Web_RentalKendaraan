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

## Cabang `uji-coba` — fitur yang dilengkapi
Di cabang ini semua berkas yang sebelumnya kosong sudah diisi:
- **Dua cara akun dibuat**: (1) registrasi mandiri di `register.html` + `api/auth.php` — penyewa langsung aktif, mitra berstatus `pending` sampai diverifikasi admin; (2) dibuatkan admin dari `admin/kelola-user.html` (form Tambah Akun, `api/admin.php` action `tambah_akun`) — admin menentukan password-nya untuk diserahkan ke pemilik akun, dan akun mitra buatan admin langsung `approved`.
- **Penyewa** (`api/user.php`): dashboard ringkasan, riwayat booking, pembatalan booking (tagihan pending ikut dibatalkan).
- **Mitra** (`api/mitra.php`): booking masuk (serah terima → aktif, pengembalian → selesai, tolak → batal), pendapatan per hari, profil usaha & rekening. Dashboard mitra & admin kini menampilkan **data nyata**, bukan contoh statis.
- **Admin** (`api/admin.php` + 8 halaman kelola): kelola user (aktif/nonaktif), kelola & verifikasi mitra, kelola mobil, kelola booking, verifikasi pembayaran manual (melunasi tagihan menerbitkan struk), laporan keuangan (pemasukan − pengeluaran), pengaturan profil & ganti password admin.

### Database: tanpa tabel yatim + 3NF
- `paket_sewa` kini benar-benar dipakai: diberi kolom `kode`, dan `booking` menunjuknya lewat FK `paket_id`. Kolom teks `booking.jenis_sewa` **dihapus** (jenis layanan cukup disimpan sekali di tabel referensi — 3NF). API tetap mengembalikan alias `jenis_sewa` berisi kode paket, jadi tampilan lama tidak berubah.
- `struk` kini diisi: satu baris struk (nomor struk + invoice + snapshot JSON) dibuat dalam transaksi yang sama saat pembayaran lunas, baik lewat callback VA maupun verifikasi admin.
- `log_aktivitas` kini ditulis pada login, register, pembuatan/pembatalan booking, pembayaran lunas, dan aksi-aksi admin/mitra.
- Tabel baru `pengeluaran` untuk laporan keuangan admin; setiap baris terikat FK `dicatat_oleh → users(id)`, tidak ada catatan yatim.
- Kolom harga di `booking` (`harga_per_hari`, `deposit`, `total_bayar`) adalah **snapshot saat transaksi** — fakta milik booking itu sendiri, memang dipertahankan.
- Instalasi baru: impor `database/rental_mobil.sql` (sudah final). Database lama: jalankan sekali `database/migrasi_uji_coba.sql`.

`pembayaran.php` tetap SIMULASI — untuk gateway sungguhan, `callback` harus diverifikasi signature dari gateway.

## Troubleshooting login

1. Pastikan Apache dan MySQL berjalan di XAMPP/Laragon.
2. Buka `http://localhost/Rental_kendaraan/login.html` (jangan buka file HTML langsung).
3. Pastikan database `rental_mobil` sudah diimpor dan tabel `users` tersedia.
4. Jika akun demo gagal, jalankan `database/reset_akun_demo.sql` melalui phpMyAdmin. Script ini hanya mereset password dan mengaktifkan akun demo, tidak menghapus data lain.
5. Akun demo: `admin@mail.com`, `mitra@mail.com`, `andi@mail.com`; password `123456`.
6. Jika masih gagal, cek Apache `error.log` dan pastikan PHP extension `mysqli` aktif.

## Catatan DOKU

Pembayaran bawaan masih simulasi. **Di branch `fitur/doku`** kerangka integrasi
DOKU Checkout sudah dibuat: `api/doku_config.php`, `api/doku_webhook.php`
(verifikasi signature + idempotent), dan `api/pembayaran.php` yang bercabang
ke DOKU bila `api/doku_secret.php` sudah diisi. Panduan lengkapnya ada di
[`DOKU.md`](DOKU.md). Secret Key tidak boleh masuk HTML/JavaScript/GitHub —
hanya file rahasia di server.
