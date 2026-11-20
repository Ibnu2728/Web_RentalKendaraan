<?php
// Pembayaran: SIMULASI VA (bawaan) atau DOKU Checkout bila sudah dikonfigurasi.
//   POST ?action=create    booking_id  -> buat tagihan; mode DOKU mengembalikan
//                                         payment_url untuk redirect ke halaman DOKU,
//                                         mode simulasi mengembalikan nomor VA.
//   POST ?action=callback  booking_id  -> simulasi "bayar sukses" (mode simulasi saja).
// Konfirmasi pembayaran DOKU yang sesungguhnya tiba lewat api/doku_webhook.php
// (dipanggil server DOKU, signature diverifikasi) — bukan dari browser user.
require 'auth_check.php';
require_once __DIR__ . '/doku_config.php';

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

    // ===== MODE DOKU (bila api/doku_secret.php sudah diisi) =====
    if (doku_aktif()) {
        // Tagihan pending lama digantikan tagihan DOKU baru (invoice DOKU
        // harus unik, jadi link lama tidak dipakai ulang).
        if ($bayar && $bayar['status'] === 'pending') {
            $batalkan = $conn->prepare("UPDATE pembayaran SET status = 'cancel' WHERE id = ?");
            $batalkan->bind_param('i', $bayar['id']);
            $batalkan->execute();
        }

        $uq = $conn->prepare("SELECT nama, email, no_hp, alamat FROM users WHERE id = ?");
        $uq->bind_param('i', $u['user_id']);
        $uq->execute();
        $usr = $uq->get_result()->fetch_assoc();

        $kode  = 'TRX-' . strtoupper(bin2hex(random_bytes(5)));
        $total = (float)$booking['total_bayar'];

        $stmt = $conn->prepare("INSERT INTO pembayaran (booking_id, kode_transaksi, metode, gross_amount, status)
                                VALUES (?, ?, 'doku', ?, 'pending')");
        $stmt->bind_param('isd', $bookingId, $kode, $total);
        if (!$stmt->execute()) {
            json_out(['success' => false, 'message' => 'Gagal membuat tagihan: ' . $stmt->error]);
        }
        $pembayaranId = (int)$stmt->insert_id;

        $payload = [
            'order' => [
                'amount'         => (int)round($total),
                'invoice_number' => $kode,
                'currency'       => 'IDR',
                'callback_url'   => doku_base_url() . '/user/struk.html?booking_id=' . $bookingId,
                'line_items'     => [[
                    'name'     => 'Sewa Mobil - Booking #' . $bookingId,
                    'price'    => (int)round($total),
                    'quantity' => 1,
                ]],
            ],
            'payment' => ['payment_due_date' => 120],
            'customer' => [
                'name'    => $usr['nama'] ?? 'Pelanggan',
                'email'   => $usr['email'] ?? '',
                'phone'   => $usr['no_hp'] ?? '',
                'address' => $usr['alamat'] ?? '',
                'country' => 'ID',
            ],
        ];

        $hasil = doku_buat_link_bayar($payload);
        if (isset($hasil['error'])) {
            $gagal = $conn->prepare("UPDATE pembayaran SET status = 'cancel' WHERE id = ?");
            $gagal->bind_param('i', $pembayaranId);
            $gagal->execute();
            json_out(['success' => false, 'message' => $hasil['error']]);
        }
        json_out(['success' => true, 'mode' => 'doku', 'payment_url' => $hasil['url'], 'total' => $total]);
    }

    // ===== MODE SIMULASI VA =====
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
