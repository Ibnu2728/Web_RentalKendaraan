<?php
// Area penyewa (role user).
//   GET  user.php?action=dashboard  -> ringkasan + 5 booking terakhir
//   GET  user.php?action=riwayat    -> semua booking milik user
//   POST user.php?action=batal      -> batalkan booking (booking_id)
// Jenis sewa dibaca dari paket_sewa lewat paket_id dan dikembalikan sebagai
// `jenis_sewa` (kode paket) agar tampilan lama tidak berubah.
require 'auth_check.php';

$u = requireRole('user');
$action = $_GET['action'] ?? '';

const BOOKING_SELECT = "SELECT b.id, b.kode_booking, b.tgl_mulai, b.tgl_selesai, b.total_hari,
        b.harga_per_hari, b.deposit, b.total_bayar, b.status, b.created_at,
        ps.kode AS jenis_sewa, ps.nama_paket,
        m.nama_mobil, m.plat_nomor, mt.nama_usaha,
        (SELECT p.status FROM pembayaran p WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1) AS status_bayar
    FROM booking b
    JOIN mobil m ON m.id = b.mobil_id
    JOIN mitra mt ON mt.id = m.mitra_id
    JOIN paket_sewa ps ON ps.id = b.paket_id";

if ($action === 'dashboard' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = $conn->prepare("SELECT
            COUNT(*) AS total,
            SUM(status IN ('dikonfirmasi','aktif')) AS berjalan,
            SUM(status = 'selesai') AS selesai,
            SUM(status = 'pending') AS pending
        FROM booking WHERE user_id = ?");
    $q->bind_param('i', $u['user_id']);
    $q->execute();
    $ringkas = $q->get_result()->fetch_assoc();

    $q = $conn->prepare(BOOKING_SELECT . " WHERE b.user_id = ? ORDER BY b.id DESC LIMIT 5");
    $q->bind_param('i', $u['user_id']);
    $q->execute();
    $terbaru = [];
    $res = $q->get_result();
    while ($row = $res->fetch_assoc()) $terbaru[] = $row;

    json_out(['success' => true, 'ringkasan' => $ringkas, 'terbaru' => $terbaru]);
}

if ($action === 'riwayat' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = $conn->prepare(BOOKING_SELECT . " WHERE b.user_id = ? ORDER BY b.id DESC");
    $q->bind_param('i', $u['user_id']);
    $q->execute();
    $list = [];
    $res = $q->get_result();
    while ($row = $res->fetch_assoc()) $list[] = $row;
    json_out(['success' => true, 'data' => $list]);
}

if ($action === 'batal' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);

    $q = $conn->prepare("SELECT id, kode_booking, status FROM booking WHERE id = ? AND user_id = ?");
    $q->bind_param('ii', $bookingId, $u['user_id']);
    $q->execute();
    $b = $q->get_result()->fetch_assoc();
    if (!$b) {
        json_out(['success' => false, 'message' => 'Booking tidak ditemukan.'], 404);
    }
    if (!in_array($b['status'], ['pending', 'dikonfirmasi'], true)) {
        json_out(['success' => false, 'message' => 'Booking dengan status "' . $b['status'] . '" tidak bisa dibatalkan.']);
    }

    $conn->begin_transaction();
    // Tagihan yang belum dibayar ikut dibatalkan; pembayaran yang sudah
    // settlement TIDAK diubah di sini (refund diproses admin secara manual).
    $a = $conn->prepare("UPDATE pembayaran SET status = 'cancel' WHERE booking_id = ? AND status = 'pending'");
    $a->bind_param('i', $bookingId);
    $c = $conn->prepare("UPDATE booking SET status = 'batal' WHERE id = ? AND status IN ('pending','dikonfirmasi')");
    $c->bind_param('i', $bookingId);

    if ($a->execute() && $c->execute() && $c->affected_rows === 1) {
        $conn->commit();
        catat_log($u['user_id'], 'booking_dibatalkan', $b['kode_booking']);
        json_out(['success' => true, 'message' => 'Booking ' . $b['kode_booking'] . ' dibatalkan.']);
    }
    $conn->rollback();
    json_out(['success' => false, 'message' => 'Gagal membatalkan booking.']);
}

json_out(['success' => false, 'message' => 'Action tidak dikenal.'], 400);
