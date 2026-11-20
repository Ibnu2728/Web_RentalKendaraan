-- ============================================================
--  MIGRASI cabang uji-coba — untuk database LAMA yang sudah terlanjur
--  diimpor dari rental_mobil.sql versi main.
--  Jalankan SEKALI lewat phpMyAdmin (tab Import) atau:
--      mysql -u root rental_mobil < migrasi_uji_coba.sql
--  Untuk instalasi BARU tidak perlu berkas ini: impor rental_mobil.sql
--  versi cabang uji-coba saja, isinya sudah final.
--
--  Isi migrasi:
--  1. paket_sewa diberi kolom `kode` (identitas stabil paket).
--  2. booking beralih dari kolom teks `jenis_sewa` ke FK `paket_id`
--     -> paket_sewa (3NF: jenis layanan cukup disimpan sekali di tabel
--     referensi; booking lama dipetakan otomatis, tidak ada data hilang).
--  3. Tabel baru `pengeluaran` (FK ke users) untuk laporan keuangan admin.
--  Tabel struk & log_aktivitas TIDAK diubah strukturnya — keduanya mulai
--  diisi oleh kode di cabang ini (struk dibuat saat pembayaran lunas,
--  log ditulis pada login/register/booking/pembayaran/aksi admin),
--  jadi tidak ada lagi tabel yatim yang tidak terpakai.
-- ============================================================
USE rental_mobil;

-- ---------- 1. paket_sewa.kode ----------
ALTER TABLE paket_sewa
  ADD COLUMN kode VARCHAR(20) NULL UNIQUE AFTER id;

UPDATE paket_sewa SET kode = 'lepas_kunci'  WHERE nama_paket = 'Lepas Kunci';
UPDATE paket_sewa SET kode = 'dengan_supir' WHERE nama_paket = 'Dengan Supir';
-- Baris paket lain (kalau ada) diberi kode dari namanya, huruf kecil & spasi -> _
UPDATE paket_sewa
   SET kode = LOWER(REPLACE(nama_paket, ' ', '_'))
 WHERE kode IS NULL;

ALTER TABLE paket_sewa
  MODIFY kode VARCHAR(20) NOT NULL;

-- ---------- 2. booking.paket_id menggantikan booking.jenis_sewa ----------
ALTER TABLE booking
  ADD COLUMN paket_id INT NULL AFTER mobil_id;

UPDATE booking b
  JOIN paket_sewa p ON p.kode = b.jenis_sewa
   SET b.paket_id = p.id;

ALTER TABLE booking
  MODIFY paket_id INT NOT NULL,
  ADD CONSTRAINT fk_booking_paket FOREIGN KEY (paket_id) REFERENCES paket_sewa(id),
  DROP COLUMN jenis_sewa;

-- ---------- 3. pengeluaran ----------
CREATE TABLE IF NOT EXISTS pengeluaran (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tanggal DATE NOT NULL,
  kategori VARCHAR(50) NOT NULL,
  keterangan VARCHAR(255),
  jumlah DECIMAL(12,2) NOT NULL,
  dicatat_oleh INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (dicatat_oleh) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_pengeluaran_tanggal (tanggal)
);
