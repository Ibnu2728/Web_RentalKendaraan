<?php
// Pembayaran SIMULASI (tanpa payment gateway sungguhan).
//   POST ?action=create    booking_id  -> buat / ambil tagihan (VA)
//   POST ?action=callback  booking_id  -> simulasi "bayar sukses"
//
// CATATAN: saat nanti memakai gateway sungguhan (Midtrans/Xendit), 'callback' HARUS
// dipanggil oleh server gateway dan diverifikasi dengan signature key — bukan oleh browser user.
require 'auth_check.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}
$u = requireRole('user');

$action    = $_GET['action'] ?? '';
$bookingId = (int)($_POST['booking_id'] ?? 0);

// Booking harus milik user yang login
$q = $conn->prepare("SELECT id, total_bayar, status FROM booking WHERE id = ? AND user_id = ?");
$q->bind_param('ii', $bookingId, $u['user_id']);
$q->execute();
$booking = $q->get_result()->fetch_assoc();
if (!$booking) json_out(['success' => false, 'message' => 'Booking tidak ditemukan.'], 404);

// Pembayaran terakhir untuk booking ini (kalau ada)
$p = $conn->prepare("SELECT * FROM pembayaran WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
$p->bind_param('i', $bookingId);
$p->execute();
$bayar = $p->get_result()->fetch_assoc();

// ---------- CREATE ----------
if ($action === 'create') {
    if ($booking['status'] !== 'pending') {
        json_out(['success' => false, 'message' => 'Booking ini sudah dibayar atau tidak aktif.']);
    }
    if ($bayar && $bayar['status'] === 'pending') {   // idempotent: refresh halaman tidak membuat tagihan baru
        json_out(['success' => true, 'va' => $bayar['va_number'], 'total' => (float)$bayar['gross_amount']]);
    }

    $kode  = 'TRX-' . strtoupper(bin2hex(random_bytes(5)));
    $va    = '8808' . random_int(10000000, 99999999);
    $total = (float)$booking['total_bayar'];

    $stmt = $conn->prepare("INSERT INTO pembayaran (booking_id, kode_transaksi, metode, gross_amount, status, va_number)
                            VALUES (?, ?, 'va', ?, 'pending', ?)");
    $stmt->bind_param('isds', $bookingId, $kode, $total, $va);
    if ($stmt->execute()) json_out(['success' => true, 'va' => $va, 'total' => $total]);
    json_out(['success' => false, 'message' => 'Gagal membuat tagihan: ' . $stmt->error]);
}

// ---------- CALLBACK (simulasi bayar) ----------
if ($action === 'callback') {
    if (!$bayar || $bayar['status'] !== 'pending' || $booking['status'] !== 'pending') {
        json_out(['success' => false, 'message' => 'Tidak ada tagihan pending untuk dibayar.']);
    }

    $conn->begin_transaction();
    $a = $conn->prepare("UPDATE pembayaran SET status='settlement', paid_at=NOW() WHERE id = ? AND status='pending'");
    $a->bind_param('i', $bayar['id']);
    $b = $conn->prepare("UPDATE booking SET status='dikonfirmasi' WHERE id = ? AND status='pending'");
    $b->bind_param('i', $bookingId);

    if ($a->execute() && $b->execute() && $a->affected_rows === 1 && $b->affected_rows === 1) {
        // Buat baris struk untuk pembayaran ini (satu pembayaran = satu struk).
        // detail_json adalah snapshot rincian saat lunas, untuk arsip cetak.
        $nomorStruk = 'STR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $noInvoice  = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $detail = json_encode([
            'kode_transaksi' => $bayar['kode_transaksi'],
            'booking_id'     => $bookingId,
            'total'          => (float)$booking['total_bayar'],
            'paid_at'        => date('Y-m-d H:i:s'),
        ]);
        $s = $conn->prepare("INSERT INTO struk (pembayaran_id, nomor_struk, no_invoice, detail_json) VALUES (?, ?, ?, ?)");
        $s->bind_param('isss', $bayar['id'], $nomorStruk, $noInvoice, $detail);
        if (!$s->execute()) {
            $conn->rollback();
            json_out(['success' => false, 'message' => 'Gagal membuat struk.']);
        }
        $conn->commit();
        catat_log($u['user_id'], 'pembayaran_lunas', $bayar['kode_transaksi']);
        json_out(['success' => true]);
    }
    $conn->rollback();
    json_out(['success' => false, 'message' => 'Gagal memproses pembayaran.']);
}

json_out(['success' => false, 'message' => 'Action tidak dikenal.'], 400);
