-- Jalankan jika akun demo tidak dapat login.
-- Password ketiga akun demo direset menjadi: 123456
USE rental_mobil;
UPDATE users SET password = '$2y$12$lawUTqfTfp5keoA/1jPQIetKNG0UtKTk9AxGlm9eACWImBwcYPgUa', status='aktif' WHERE email IN ('admin@mail.com','mitra@mail.com','andi@mail.com');
-- Pastikan mitra demo memiliki profil:
INSERT INTO mitra (user_id, nama_usaha, no_sipa, status_verifikasi, rekening_bank, no_rekening, nama_rekening)
SELECT id, 'PT Sewa Jaya', 'SIPA-001', 'approved', 'BCA', '1234567890', 'PT Sewa Jaya'
FROM users WHERE email='mitra@mail.com'
AND NOT EXISTS (SELECT 1 FROM mitra WHERE user_id=(SELECT id FROM users WHERE email='mitra@mail.com'));
