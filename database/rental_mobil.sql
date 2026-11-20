-- ============================================================
--  Database Rental Mobil
--  Impor lewat phpMyAdmin (tab Import) atau: mysql -u root < rental_mobil.sql
--  Perubahan dari skema awal: IF NOT EXISTS, utf8mb4, plat_nomor UNIQUE,
--  index untuk cek bentrok jadwal, + data awal (seed) di bagian bawah.
-- ============================================================
CREATE DATABASE IF NOT EXISTS rental_mobil CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rental_mobil;

-- ============ USERS (3 role) ============
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  email VARCHAR(100) UNIQUE NOT NULL,
  password VARCHAR(255) NOT NULL,
  no_hp VARCHAR(20),
  alamat TEXT,
  role ENUM('user','admin','mitra') DEFAULT 'user',
  foto_ktp VARCHAR(255),
  status ENUM('aktif','nonaktif') DEFAULT 'aktif',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============ MITRA (detail penyedia mobil) ============
CREATE TABLE IF NOT EXISTS mitra (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  nama_usaha VARCHAR(150),
  no_sipa VARCHAR(100),
  foto_sipa VARCHAR(255),
  status_verifikasi ENUM('pending','approved','rejected') DEFAULT 'pending',
  rekening_bank VARCHAR(50),
  no_rekening VARCHAR(50),
  nama_rekening VARCHAR(100),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============ MOBIL ============
CREATE TABLE IF NOT EXISTS mobil (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mitra_id INT NOT NULL,
  nama_mobil VARCHAR(100) NOT NULL,
  merk VARCHAR(50),
  plat_nomor VARCHAR(20) UNIQUE,
  tahun YEAR,
  transmisi ENUM('manual','matic'),
  kapasitas INT,
  harga_lepas_kunci DECIMAL(12,2) DEFAULT 0,
  harga_dengan_supir DECIMAL(12,2) DEFAULT 0,
  deposit DECIMAL(12,2) DEFAULT 500000,
  foto_utama VARCHAR(255),
  foto_360 VARCHAR(255),
  deskripsi TEXT,
  status ENUM('tersedia','disewa','nonaktif') DEFAULT 'tersedia',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (mitra_id) REFERENCES mitra(id) ON DELETE CASCADE
);

-- ============ PAKET SEWA ============
-- Tabel referensi jenis layanan. `kode` adalah identitas stabil paket
-- ('lepas_kunci' / 'dengan_supir') yang dipakai form & URL; booking menunjuk
-- ke sini lewat paket_id (FK) — BUKAN menyimpan teks jenis sendiri (3NF).
CREATE TABLE IF NOT EXISTS paket_sewa (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kode VARCHAR(20) NOT NULL UNIQUE,
  nama_paket VARCHAR(50),
  deskripsi TEXT,
  butuh_ktp BOOLEAN DEFAULT 1,
  butuh_sipa BOOLEAN DEFAULT 1,
  deposit_default DECIMAL(12,2) DEFAULT 500000,
  tarif_supir DECIMAL(12,2) DEFAULT 0,
  durasi_supir_jam INT DEFAULT 12
);

-- ============ BOOKING ============
CREATE TABLE IF NOT EXISTS booking (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kode_booking VARCHAR(20) UNIQUE,
  user_id INT NOT NULL,
  mobil_id INT NOT NULL,
  paket_id INT NOT NULL,
  tgl_mulai DATE NOT NULL,
  tgl_selesai DATE NOT NULL,
  total_hari INT,
  harga_per_hari DECIMAL(12,2),
  biaya_supir DECIMAL(12,2) DEFAULT 0,
  deposit DECIMAL(12,2) DEFAULT 0,
  total_bayar DECIMAL(12,2),
  catatan TEXT,
  status ENUM('pending','dikonfirmasi','aktif','selesai','batal') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (mobil_id) REFERENCES mobil(id),
  FOREIGN KEY (paket_id) REFERENCES paket_sewa(id),
  INDEX idx_booking_jadwal (mobil_id, tgl_mulai, tgl_selesai)
);
-- Catatan 3NF: harga_per_hari, biaya_supir, deposit, dan total_bayar di tabel
-- booking adalah SNAPSHOT harga saat transaksi terjadi (fakta milik booking
-- itu sendiri), bukan salinan hidup dari tabel mobil — harga mobil boleh
-- berubah besok, booking lama tidak ikut berubah. Karena itu dipertahankan.

-- ============ PEMBAYARAN ============
CREATE TABLE IF NOT EXISTS pembayaran (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  kode_transaksi VARCHAR(50) UNIQUE,
  metode ENUM('transfer','midtrans','xendit','qris','va') DEFAULT 'midtrans',
  gross_amount DECIMAL(12,2),
  status ENUM('pending','settlement','expire','cancel') DEFAULT 'pending',
  va_number VARCHAR(50),
  pdf_url VARCHAR(255),
  paid_at DATETIME,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES booking(id) ON DELETE CASCADE
);

-- ============ STRUK ============
CREATE TABLE IF NOT EXISTS struk (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pembayaran_id INT NOT NULL,
  nomor_struk VARCHAR(30) UNIQUE,
  no_invoice VARCHAR(30) UNIQUE,
  detail_json JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pembayaran_id) REFERENCES pembayaran(id) ON DELETE CASCADE
);

-- ============ PENGELUARAN (operasional, dicatat admin) ============
-- Setiap baris terikat ke admin pencatatnya lewat FK dicatat_oleh -> users,
-- jadi tidak ada catatan keuangan yang yatim. Semua kolom non-kunci hanya
-- bergantung pada id pengeluaran ini sendiri (3NF).
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

-- ============ LOG AKTIVITAS ============
CREATE TABLE IF NOT EXISTS log_aktivitas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  aksi VARCHAR(100),
  detail TEXT,
  ip_address VARCHAR(50),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
--  DATA AWAL (SEED) — password semua akun demo: 123456
-- ============================================================
INSERT INTO users (id, nama, email, password, no_hp, role) VALUES
 (1, 'Admin Utama', 'admin@mail.com', '$2y$10$F7lo04UpypDByLPwFz8GueVnWrcs1ZJ8YQNsXozeXPzEMQW89VWJi', '081100000001', 'admin'),
 (2, 'Pak Mitra',   'mitra@mail.com', '$2y$10$F7lo04UpypDByLPwFz8GueVnWrcs1ZJ8YQNsXozeXPzEMQW89VWJi', '081100000002', 'mitra'),
 (3, 'Andi',        'andi@mail.com',  '$2y$10$F7lo04UpypDByLPwFz8GueVnWrcs1ZJ8YQNsXozeXPzEMQW89VWJi', '081100000003', 'user');

INSERT INTO mitra (id, user_id, nama_usaha, no_sipa, status_verifikasi, rekening_bank, no_rekening, nama_rekening) VALUES
 (1, 2, 'PT Sewa Jaya', 'SIPA-001', 'approved', 'BCA', '1234567890', 'PT Sewa Jaya');

INSERT INTO mobil (mitra_id, nama_mobil, merk, plat_nomor, tahun, transmisi, kapasitas, harga_lepas_kunci, harga_dengan_supir, deposit, deskripsi) VALUES
 (1, 'Toyota Avanza', 'Toyota',   'B 1234 ABC', 2022, 'manual', 7, 350000, 650000, 500000, 'AC, audio, cocok untuk keluarga.'),
 (1, 'Toyota Innova', 'Toyota',   'B 2345 BCD', 2021, 'matic',  7, 500000, 800000, 500000, 'Nyaman untuk perjalanan jauh.'),
 (1, 'Honda Brio',    'Honda',    'B 3456 CDE', 2023, 'matic',  5, 300000, 550000, 500000, 'Irit dan lincah untuk dalam kota.');

INSERT INTO paket_sewa (kode, nama_paket, deskripsi, butuh_ktp, butuh_sipa, deposit_default, tarif_supir, durasi_supir_jam) VALUES
 ('lepas_kunci',  'Lepas Kunci',  'KTP elektronik & SIPA asli, deposit jaminan, serah terima foto 360.', 1, 1, 500000, 0, 12),
 ('dengan_supir', 'Dengan Supir', 'Bebas deposit, supir berpengalaman, durasi kerja 12 jam/hari.',      1, 0, 0,      0, 12);
