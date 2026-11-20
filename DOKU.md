# Panduan Integrasi DOKU Checkout — Branch `fitur/doku`

Branch ini berisi **kerangka** integrasi DOKU Checkout (Jokul API) untuk
SewaMobil. Selama kredensial belum diisi, pembayaran tetap berjalan dalam
mode **simulasi VA** seperti sebelumnya — tidak ada yang rusak. Begitu file
rahasia diisi, mode otomatis beralih ke DOKU.

## File yang terlibat

| File | Fungsi |
| --- | --- |
| `api/doku_config.php` | Konfigurasi + helper signature HMAC-SHA256 dan pemanggilan API DOKU (ter-commit, aman) |
| `api/doku_secret.contoh.php` | Contoh file rahasia — salin jadi `api/doku_secret.php` lalu isi |
| `api/doku_secret.php` | Kredensial ASLI. **Tidak ter-commit** (ada di `.gitignore`) |
| `api/doku_webhook.php` | Endpoint notifikasi dari server DOKU: verifikasi signature → lunasi pembayaran → konfirmasi booking → terbitkan struk (idempotent) |
| `api/pembayaran.php` | `action=create` bercabang: mode DOKU mengembalikan `payment_url`, mode simulasi mengembalikan nomor VA seperti biasa |

## Langkah 1 — Daftar DOKU Sandbox

1. Buka https://sandbox.doku.com dan buat akun (gratis, untuk pengujian).
2. Masuk ke dashboard sandbox → menu integrasi/API → catat **Client ID** dan
   **Secret Key**.
3. Untuk go-live nanti, ulangi di https://dashboard.doku.com dan ganti
   `mode` menjadi `production` di file rahasia.

## Langkah 1b — Jalankan migrasi database DOKU

Di phpMyAdmin, pilih database `rental_mobil`, tab **Import**, jalankan
`database/migrasi_doku.sql` (sekali saja). File itu menambahkan nilai
`'doku'` ke ENUM `pembayaran.metode` — tanpa ini, pembuatan tagihan DOKU
gagal dengan error *Data truncated for column 'metode'*.

## Langkah 2 — Isi file rahasia di server

```bash
cp api/doku_secret.contoh.php api/doku_secret.php
```

Lalu edit `api/doku_secret.php`:

- `client_id` & `secret_key` — dari Langkah 1.
- `mode` — `sandbox` selama pengujian.
- `base_url` — alamat publik situs TANPA `/` di akhir. Dipakai sebagai
  alamat kembali setelah user membayar di halaman DOKU.
- `webhook_target` — path endpoint webhook **persis** seperti URL yang
  didaftarkan di dashboard DOKU (dipakai saat verifikasi signature).

> ⚠️ Server DOKU tidak bisa menjangkau `localhost`. Untuk pengujian lokal,
> jalankan **ngrok** (`ngrok http 80`) lalu pakai alamat ngrok sebagai
> `base_url` dan di `webhook_target` (contoh:
> `/Rental_Kendaraan/api/doku_webhook.php` bila folder proyek di htdocs
> bernama `Rental_Kendaraan`).

## Langkah 3 — Daftarkan Notification URL di dashboard DOKU

Di dashboard DOKU (menu pengaturan notifikasi / webhook), isi URL:

```
{base_url}/api/doku_webhook.php
```

Contoh saat memakai ngrok:
`https://abcd-1234.ngrok-free.app/Rental_Kendaraan/api/doku_webhook.php`

Pastikan path-nya sama persis dengan `webhook_target` di file rahasia —
kalau beda satu huruf saja, verifikasi signature gagal (HTTP 401) dan
pembayaran tidak akan terkonfirmasi otomatis.

## Langkah 4 — Uji pembayaran sandbox

1. Login sebagai user demo → buat booking → buka halaman pembayaran.
2. Judul halaman berubah jadi **"Pembayaran via DOKU"** dan tombol mengarah
   ke halaman pembayaran DOKU (kalau masih tampil VA simulasi, berarti
   `api/doku_secret.php` belum ada / belum terisi dengan benar).
3. Bayar memakai instrumen pengujian dari dashboard sandbox DOKU
   (VA / kartu uji yang disediakan DOKU untuk sandbox).
4. Setelah lunas, DOKU memanggil webhook. Hasil yang diharapkan:
   - tabel `pembayaran`: baris `metode = 'doku'` berstatus `settlement`,
   - tabel `booking`: status menjadi `dikonfirmasi`,
   - tabel `struk`: muncul satu baris struk untuk pembayaran itu,
   - user kembali ke halaman struk dan struknya tampil.
5. Uji juga skenario gagal/expired dan pengiriman notifikasi ganda
   (webhook dirancang idempotent — data tidak boleh dobel).

## Keamanan (sudah diterapkan, jangan dilonggarkan)

- Secret Key hanya hidup di `api/doku_secret.php` di server — tidak pernah
  dikirim ke browser, tidak masuk JavaScript, tidak ter-commit.
- Webhook menolak request tanpa signature yang valid (401) — jangan pernah
  melunasi pembayaran berdasarkan redirect browser user; satu-satunya bukti
  lunas adalah notifikasi server DOKU yang terverifikasi.
- Setiap invoice (`kode_transaksi`) unik; tagihan pending lama dibatalkan
  bila user membuat tagihan DOKU baru.

## Troubleshooting

| Gejala | Cek |
| --- | --- |
| Halaman pembayaran masih mode VA | `api/doku_secret.php` belum ada / `client_id` masih berisi `xxxx` |
| Error *Data truncated for column 'metode'* | Migrasi DOKU belum dijalankan — import `database/migrasi_doku.sql` (Langkah 1b) |
| Error *Invalid Header Signature* dari DOKU | Pastikan kode terbaru (`HMACSHA256=` tanpa tanda hubung). Bila masih gagal: Client ID / Secret tertukar mode (kredensial sandbox dipakai di production atau sebaliknya), atau Secret terpotong/kena spasi saat menyalin |
| Webhook selalu 401 | `webhook_target` tidak sama persis dengan URL terdaftar, atau Secret Key salah mode (sandbox vs production) |
| Sudah bayar tapi booking belum `dikonfirmasi` | Webhook tidak terjangkau DOKU (localhost tanpa ngrok?) atau URL notifikasi belum didaftarkan |
| Error "DOKU menolak permintaan" | Lihat pesan errornya — umumnya nominal/invoice tidak valid atau kredensial salah |
| `curl` error di XAMPP | Aktifkan extension `curl` di `php.ini`, restart Apache |

## Setelah lolos uji

Buat Pull Request `fitur/doku` → `uji-coba` untuk direview bersama, sama
seperti alur di `PANDUAN-BRANCH-UJI-COBA.md`.
