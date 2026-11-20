<?php
// Konfigurasi koneksi + helper dasar. Di-include oleh semua endpoint API.

// Jangan tampilkan warning PHP ke browser (merusak JSON). Error tetap masuk error_log.
ini_set('display_errors', '0');
error_reporting(E_ALL);

// PHP 8.1+ secara default melempar exception pada error mysqli; kode ini memakai
// pola "cek $stmt->execute()", jadi matikan mode exception.
mysqli_report(MYSQLI_REPORT_OFF);

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function json_out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'rental_mobil';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    json_out(['success' => false, 'message' => 'Koneksi database gagal.'], 500);
}
$conn->set_charset('utf8mb4');
