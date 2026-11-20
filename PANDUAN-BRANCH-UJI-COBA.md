# Panduan Branch `uji-coba` — SewaMobil

Branch ini berisi pengembangan lanjutan dari `main`: semua halaman & API yang
sebelumnya kosong sudah diisi, dan skema database sudah dirapikan
(tidak ada tabel yatim, mengikuti 3NF). Branch `main` **tidak diubah sama
sekali** — semua pekerjaan ada di `uji-coba` sampai dinyatakan siap merge.

---

## 1. Apa saja yang baru di branch ini

| Area | Yang ditambahkan |
| --- | --- |
| Registrasi | `register.html` + `api/auth.php` — daftar sebagai penyewa (langsung aktif) atau mitra (menunggu verifikasi admin) |
| Penyewa | `user/dashboard.html`, `user/riwayat.html` + `api/user.php` — ringkasan, riwayat, batalkan booking |
| Mitra | `mitra/booking-masuk.html`, `mitra/pendapatan.html`, `mitra/profil.html` + `api/mitra.php` — serah terima → aktif → selesai, pendapatan per hari, profil & rekening |
| Admin | `api/admin.php` + 8 halaman: kelola user, kelola mitra, verifikasi mitra, kelola mobil, kelola booking, verifikasi pembayaran, laporan keuangan, pengaturan |
| Dashboard | Statistik & tabel di dashboard admin dan mitra sekarang **data nyata** dari database (bukan angka contoh) |
| Database | `paket_sewa` terhubung ke `booking` lewat `paket_id`, `struk` dibuat otomatis saat pembayaran lunas, `log_aktivitas` benar-benar ditulis, tabel baru `pengeluaran` untuk laporan keuangan |

Detail perubahan database ada di bagian 4.

---

## 2. Cara mengambil branch ini

### Pilihan A — repo sudah ada di laptop (paling umum)

Jalankan di folder proyek:

```bash
git fetch origin
git checkout uji-coba
git pull origin uji-coba
```

Semua file langsung berubah ke versi branch ini.
Mau kembali ke versi lama? `git checkout main`.

### Pilihan B — clone baru (folder terpisah)

```bash
git clone -b uji-coba https://github.com/Ibnu2728/Web_RentalKendaraan.git
```

### Pilihan C — tanpa git

Buka repo di GitHub → tombol dropdown branch → pilih `uji-coba` →
tombol **Code** → **Download ZIP**.

---

## 3. Cara menjalankan

1. Nyalakan **Apache** dan **MySQL** di XAMPP/Laragon.
2. Siapkan database sesuai bagian 4 di bawah.
3. Buka `http://localhost/Rental_kendaraan/login.html`
   (jangan membuka file HTML langsung / `file://`, session login tidak akan jalan).

### Akun demo (password semua: `123456`)

| Role | Email | Masuk ke |
| --- | --- | --- |
| User | andi@mail.com | `user/katalog.html` |
| Mitra | mitra@mail.com | `mitra/dashboard.html` |
| Admin | admin@mail.com | `admin/dashboard.html` |

---

## 4. Database — WAJIB pilih salah satu

Skema di branch ini berbeda dari `main`, jadi database lama perlu disesuaikan.

### Pilihan A — database lama dipertahankan (data tidak hilang)

1. Buka phpMyAdmin, pilih database `rental_mobil`.
2. Tab **Import** → pilih file `database/migrasi_uji_coba.sql` → **Go**.
3. Jalankan **sekali saja**.

Isi migrasi: `paket_sewa` diberi kolom `kode`; `booking` beralih dari kolom
teks `jenis_sewa` ke FK `paket_id` (data booking lama dipetakan otomatis);
tabel baru `pengeluaran` dibuat.

### Pilihan B — mulai dari database bersih

1. Hapus database `rental_mobil` lama (atau buat database baru bernama sama).
2. Import `database/rental_mobil.sql` dari branch ini — isinya sudah final.

### Kenapa skemanya berubah? (ringkas 3NF & tabel yatim)

- Di `main`, tabel `paket_sewa`, `struk`, dan `log_aktivitas` ada tapi tidak
  pernah dipakai kode — itu **tabel yatim**. Sekarang ketiganya terhubung dan
  terisi: booking menunjuk paket lewat FK, struk dibuat saat pembayaran lunas,
  log ditulis pada aktivitas penting.
- `booking.jenis_sewa` (teks) menduplikasi data milik `paket_sewa`, jadi
  diganti `paket_id`. API tetap mengembalikan alias `jenis_sewa` (kode paket),
  sehingga tampilan lama tidak berubah.
- Kolom harga di `booking` adalah **snapshot saat transaksi** — memang
  dipertahankan, supaya harga lama tidak ikut berubah saat harga mobil diubah.

---

## 5. Checklist uji fitur baru

Supaya gampang mengecek semuanya jalan:

**Penyewa**
- [ ] Daftar akun baru di `register.html` → login → katalog → checkout → bayar (simulasi VA) → struk tampil.
- [ ] Buka `user/dashboard.html` & `user/riwayat.html` → booking muncul; batalkan satu booking pending.

**Mitra**
- [ ] Daftar sebagai mitra → login **ditolak** (masih pending) → setelah disetujui admin, login berhasil.
- [ ] `mitra/booking-masuk.html`: ubah booking lunas menjadi Aktif (serah terima), lalu Selesai.
- [ ] `mitra/pendapatan.html` & `mitra/profil.html` tampil dan bisa disimpan.

**Admin**
- [ ] `admin/verifikasi-mitra.html`: setujui mitra yang baru daftar.
- [ ] `admin/dashboard.html`: angka statistik sesuai isi database.
- [ ] `admin/laporan-keuangan.html`: catat satu pengeluaran → saldo berkurang.
- [ ] `admin/pengaturan.html`: ganti password admin → logout → login dengan password baru.

---

## 6. Cara bekerja di branch ini ke depan

- Semua perubahan baru kerjakan di `uji-coba`, jangan langsung di `main`:

  ```bash
  git checkout uji-coba
  git pull origin uji-coba      # ambil perubahan terbaru dulu
  # ... kerjakan perubahan ...
  git add .
  git commit -m "pesan perubahan"
  git push origin uji-coba
  ```

- Karena branch ini dipakai bersama, biasakan `git pull` sebelum mulai dan
  sebelum `push`, supaya tidak tertimpa pekerjaan orang lain.

---

## 7. Kalau sudah siap: merge ke `main`

1. Buka repo di GitHub → akan muncul tombol **Compare & pull request**
   (atau menu **Pull requests → New pull request**).
2. Atur: **base: `main`** ← **compare: `uji-coba`** → **Create pull request**.
3. Review daftar perubahan (Files changed), lalu **Merge pull request** →
   **Confirm merge**.
4. Setelah merge, di laptop: `git checkout main` lalu `git pull origin main`.

---

## 8. Troubleshooting

| Gejala | Penyebab & solusi |
| --- | --- |
| Error soal tabel/kolom tidak ditemukan (`paket_id`, `pengeluaran`, `kode`) | Migrasi belum dijalankan — lakukan bagian 4 pilihan A, atau impor ulang (pilihan B) |
| Mitra baru tidak bisa login | Memang begitu: akun mitra harus disetujui dulu di `admin/verifikasi-mitra.html` |
| Login selalu gagal / kembali ke halaman login | Dibuka lewat `file://` atau MySQL mati — buka lewat `http://localhost/...` dan nyalakan MySQL |
| Akun demo tidak bisa dipakai | Jalankan `database/reset_akun_demo.sql` di phpMyAdmin |
| Grafik tidak muncul | Chart.js dimuat dari CDN — butuh koneksi internet |

---

## 9. Catatan

- Pembayaran masih **simulasi VA** (sama seperti di `main`). Rencana integrasi
  DOKU ada di `README.md`.
- Verifikasi pembayaran oleh admin (menu **Verifikasi Pembayaran**) disediakan
  untuk pembayaran manual: menyetujui tagihan pending akan melunaskannya,
  mengonfirmasi booking, dan menerbitkan nomor struk.
