<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

$email = strtolower(trim($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
if ($email === '' || $password === '') {
    json_out(['success' => false, 'message' => 'Email dan password wajib diisi.'], 400);
}

$stmt = $conn->prepare("SELECT id, nama, email, password, role FROM users WHERE email = ? AND status = 'aktif' LIMIT 1");
if (!$stmt) {
    error_log('Login prepare failed: ' . $conn->error);
    json_out(['success' => false, 'message' => 'Login sedang bermasalah. Periksa konfigurasi database.'], 500);
}
$stmt->bind_param('s', $email);
if (!$stmt->execute()) {
    error_log('Login execute failed: ' . $stmt->error);
    json_out(['success' => false, 'message' => 'Tidak dapat memproses login.'], 500);
}
$user = $stmt->get_result()->fetch_assoc();

if (!$user || !password_verify($password, $user['password'])) {
    json_out(['success' => false, 'message' => 'Email atau password salah.']);
}

if ($user['role'] === 'mitra') {
    $q = $conn->prepare("SELECT id, status_verifikasi FROM mitra WHERE user_id = ? LIMIT 1");
    $q->bind_param('i', $user['id']);
    $q->execute();
    $m = $q->get_result()->fetch_assoc();
    if (!$m) {
        json_out(['success' => false, 'message' => 'Akun mitra belum memiliki profil penyedia. Hubungi admin.'], 403);
    }
    if ($m['status_verifikasi'] !== 'approved') {
        json_out(['success' => false, 'message' => 'Akun mitra Anda ' . ($m['status_verifikasi'] === 'rejected' ? 'ditolak' : 'masih menunggu verifikasi admin') . '.'], 403);
    }
}

session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['role'] = $user['role'];
$_SESSION['nama'] = $user['nama'];
unset($_SESSION['mitra_id']);

if ($user['role'] === 'mitra') {
    $_SESSION['mitra_id'] = (int)$m['id'];
}

catat_log((int)$user['id'], 'login', $user['role']);
json_out(['success' => true, 'role' => $user['role'], 'nama' => $user['nama']]);
