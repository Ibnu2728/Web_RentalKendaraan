<?php
// Registrasi akun baru.
//   POST auth.php  (role=user)  : nama, email, password, konfirmasi, no_hp, alamat
//   POST auth.php  (role=mitra) : + nama_usaha, no_sipa
// Akun user langsung aktif. Akun mitra dibuat bersama baris di tabel mitra
// berstatus 'pending' — baru bisa dipakai setelah diverifikasi admin
// (lihat api/admin.php action=verifikasi_mitra). Satu transaksi untuk
// keduanya, jadi tidak ada akun mitra yatim tanpa profil penyedianya.
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

$nama       = trim($_POST['nama'] ?? '');
$email      = strtolower(trim($_POST['email'] ?? ''));
$password   = (string)($_POST['password'] ?? '');
$konfirmasi = (string)($_POST['konfirmasi'] ?? '');
$noHp       = trim($_POST['no_hp'] ?? '');
$alamat     = trim($_POST['alamat'] ?? '');
$role       = $_POST['role'] ?? 'user';

if (!in_array($role, ['user', 'mitra'], true)) {
    json_out(['success' => false, 'message' => 'Role tidak valid.']);
}
if ($nama === '' || $email === '' || $password === '') {
    json_out(['success' => false, 'message' => 'Nama, email, dan password wajib diisi.'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['success' => false, 'message' => 'Format email tidak valid.'], 400);
}
if (strlen($password) < 6) {
    json_out(['success' => false, 'message' => 'Password minimal 6 karakter.'], 400);
}
if ($konfirmasi !== '' && $konfirmasi !== $password) {
    json_out(['success' => false, 'message' => 'Konfirmasi password tidak sama.'], 400);
}

$namaUsaha = trim($_POST['nama_usaha'] ?? '');
$noSipa    = trim($_POST['no_sipa'] ?? '');
if ($role === 'mitra' && $namaUsaha === '') {
    json_out(['success' => false, 'message' => 'Nama usaha wajib diisi untuk pendaftaran mitra.'], 400);
}

// Email tidak boleh dobel.
$cek = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$cek->bind_param('s', $email);
$cek->execute();
if ($cek->get_result()->fetch_assoc()) {
    json_out(['success' => false, 'message' => 'Email sudah terdaftar. Silakan login.'], 409);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$conn->begin_transaction();

$stmt = $conn->prepare("INSERT INTO users (nama, email, password, no_hp, alamat, role, status) VALUES (?, ?, ?, ?, ?, ?, 'aktif')");
$stmt->bind_param('ssssss', $nama, $email, $hash, $noHp, $alamat, $role);
if (!$stmt->execute()) {
    $conn->rollback();
    json_out(['success' => false, 'message' => 'Gagal mendaftar: ' . $stmt->error], 500);
}
$userId = (int)$stmt->insert_id;

if ($role === 'mitra') {
    $m = $conn->prepare("INSERT INTO mitra (user_id, nama_usaha, no_sipa, status_verifikasi) VALUES (?, ?, ?, 'pending')");
    $m->bind_param('iss', $userId, $namaUsaha, $noSipa);
    if (!$m->execute()) {
        $conn->rollback();
        json_out(['success' => false, 'message' => 'Gagal membuat profil mitra: ' . $m->error], 500);
    }
}

$conn->commit();
catat_log($userId, 'register', $role);

json_out([
    'success' => true,
    'message' => $role === 'mitra'
        ? 'Pendaftaran mitra berhasil. Akun menunggu verifikasi admin sebelum bisa dipakai login.'
        : 'Pendaftaran berhasil. Silakan login.',
]);
