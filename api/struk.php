<?php
// Struk pembayaran. Boleh dilihat: pemilik booking, mitra pemilik mobil, atau admin.
require 'auth_check.php';
$u = requireLogin();

$bookingId = (int)($_GET['booking_id'] ?? 0);

$stmt = $conn->prepare("SELECT b.*, ps.kode AS jenis_sewa, m.nama_mobil, m.mitra_id, u.nama AS penyewa,
               p.kode_transaksi, p.paid_at, s.nomor_struk, s.no_invoice
        FROM booking b
        JOIN mobil m ON b.mobil_id = m.id
        JOIN users u ON b.user_id = u.id
        JOIN paket_sewa ps ON ps.id = b.paket_id
        JOIN pembayaran p ON p.booking_id = b.id AND p.status = 'settlement'
        LEFT JOIN struk s ON s.pembayaran_id = p.id
        WHERE b.id = ?
        ORDER BY p.id DESC LIMIT 1");
$stmt->bind_param('i', $bookingId);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

$boleh = $data && (
    $u['role'] === 'admin' ||
    ($u['role'] === 'user'  && (int)$data['user_id']  === $u['user_id']) ||
    ($u['role'] === 'mitra' && (int)$data['mitra_id'] === (int)($_SESSION['mitra_id'] ?? 0))
);

if (!$boleh) {
    json_out(['success' => false, 'message' => 'Struk tidak ditemukan atau pembayaran belum selesai.'], 404);
}
json_out(['success' => true, 'data' => $data]);
