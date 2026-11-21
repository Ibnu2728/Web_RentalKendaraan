<?php
// Struk / invoice pembayaran. Boleh dilihat: pemilik booking, mitra pemilik mobil, atau admin.
// Bila pembayaran sudah settlement -> data struk. Bila belum -> data invoice
// (halaman menampilkan tagihan + status menunggu verifikasi, bukan pesan error).
require 'auth_check.php';
$u = requireLogin();

$bookingId = (int)($_GET['booking_id'] ?? 0);

$stmt = $conn->prepare("SELECT b.*, ps.kode AS jenis_sewa, m.nama_mobil, m.mitra_id, u.nama AS penyewa
        FROM booking b
        JOIN mobil m ON b.mobil_id = m.id
        JOIN users u ON b.user_id = u.id
        JOIN paket_sewa ps ON ps.id = b.paket_id
        WHERE b.id = ?");
$stmt->bind_param('i', $bookingId);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

$boleh = $data && (
    $u['role'] === 'admin' ||
    ($u['role'] === 'user'  && (int)$data['user_id']  === $u['user_id']) ||
    ($u['role'] === 'mitra' && (int)$data['mitra_id'] === (int)($_SESSION['mitra_id'] ?? 0))
);

if (!$boleh) {
    json_out(['success' => false, 'message' => 'Booking tidak ditemukan.'], 404);
}

$stmt = $conn->prepare("SELECT p.kode_transaksi, p.metode, p.gross_amount, p.status AS p_status,
               p.va_number, p.paid_at, s.nomor_struk, s.no_invoice
        FROM pembayaran p
        LEFT JOIN struk s ON s.pembayaran_id = p.id
        WHERE p.booking_id = ?
        ORDER BY p.id DESC LIMIT 1");
$stmt->bind_param('i', $bookingId);
$stmt->execute();
$bayar = $stmt->get_result()->fetch_assoc();

$data['kode_transaksi'] = $bayar['kode_transaksi'] ?? null;
$data['metode']         = $bayar['metode'] ?? null;
$data['gross_amount']   = $bayar['gross_amount'] ?? null;
$data['p_status']       = $bayar['p_status'] ?? null;
$data['va_number']      = $bayar['va_number'] ?? null;
$data['paid_at']        = $bayar['paid_at'] ?? null;
$data['nomor_struk']    = $bayar['nomor_struk'] ?? null;
$data['no_invoice']     = $bayar['no_invoice'] ?? null;

json_out(['success' => true, 'data' => $data]);
