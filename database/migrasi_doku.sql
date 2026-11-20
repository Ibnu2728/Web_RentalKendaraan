-- ============================================================
--  MIGRASI DOKU — jalankan SEKALI di phpMyAdmin (pilih database
--  rental_mobil dulu) sebelum menguji pembayaran DOKU.
--
--  Alasan: kolom pembayaran.metode adalah ENUM bawaan proyek yang
--  isinya ('transfer','midtrans','xendit','qris','va') — belum ada
--  'doku'. Tanpa baris di bawah ini, membuat tagihan DOKU gagal
--  dengan error: Data truncated for column 'metode' at row 1.
--  Aman dijalankan ulang: isinya hanya memastikan 'doku' termasuk
--  dalam daftar ENUM.
-- ============================================================
USE rental_mobil;

ALTER TABLE pembayaran
  MODIFY metode ENUM('transfer','midtrans','xendit','qris','va','doku') DEFAULT 'midtrans';
