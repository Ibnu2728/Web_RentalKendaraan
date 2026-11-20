<?php
// ============================================================
//  Webhook / Notification Handler DOKU.
//  Endpoint ini dipanggil SERVER DOKU (bukan browser user) saat status
//  pembayaran berubah. URL-nya didaftarkan di dashboard DOKU dan harus
//  bisa dijangkau dari internet (localhost tidak bisa — pakai ngrok
//  untuk pengujian lokal, lihat DOKU.md).
//
//  Keamanan: signature HMAC-SHA256 WAJIB cocok, selain itu ditolak 401.
//  Idempotent: notifikasi yang sama dikirim ulang oleh DOKU tidak akan
//  memproses pembayaran dua kali.
// ============================================================
require __DIR__ . '/config.php';
require __DIR__ . '/doku_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}
if (!doku_aktif()) {
    json_out(['success' => false, 'message' => 'DOKU belum dikonfigurasi di server.'], 503);
}

$raw = (string)file_get_contents('php://input');
if (!doku_verifikasi_notifikasi($raw, doku_semua_header())) {
    json_out(['success' => false, 'message' => 'Signature tidak valid.'], 401);
}

$data = json_decode($raw, true);
$invoice = (string)($data['order']['invoice_number'] ?? '');
$statusTx = strtoupper((string)($data['transaction']['status'] ?? ''));
if ($invoice === '') {
    json_out(['success' => false, 'message' => 'invoice_number kosong.'], 400);
}

$q = $conn->prepare("SELECT p.*, b.user_id, b.status AS status_booking
                     FROM pembayaran p JOIN booking b ON b.id = p.booking_id
                     WHERE p.kode_transaksi = ? LIMIT 1");
$q->bind_param('s', $invoice);
$q->execute();
$bayar = $q->get_result()->fetch_assoc();
if (!$bayar) {
    json_out(['success' => false, 'message' => 'Tagihan tidak ditemukan.'], 404);
}

// Sudah lunas sebelumnya -> notifikasi duplikat, jawab OK saja (idempotent).
if ($bayar['status'] === 'settlement') {
    json_out(['success' => true, 'message' => 'Sudah diproses sebelumnya.']);
}

if ($statusTx === 'FAILED') {
    $up = $conn->prepare("UPDATE pembayaran SET status = 'expire' WHERE id = ? AND status = 'pending'");
    $up->bind_param('i', $bayar['id']);
    $up->execute();
    catat_log((int)$bayar['user_id'], 'pembayaran_gagal_doku', $invoice);
    json_out(['success' => true, 'message' => 'Kegagalan pembayaran dicatat.']);
}

if ($statusTx !== 'SUCCESS') {
    json_out(['success' => true, 'message' => 'Status "' . $statusTx . '" diterima, tidak ada perubahan.']);
}

// ---- Pembayaran SUKSES: lunasi + konfirmasi booking + terbitkan struk ----
$conn->begin_transaction();

$a = $conn->prepare("UPDATE pembayaran SET status = 'settlement', paid_at = NOW() WHERE id = ? AND status = 'pending'");
$a->bind_param('i', $bayar['id']);
$b = $conn->prepare("UPDATE booking SET status = 'dikonfirmasi' WHERE id = ? AND status = 'pending'");
$b->bind_param('i', $bayar['booking_id']);

if (!($a->execute() && $b->execute() && $a->affected_rows === 1 && $b->affected_rows === 1)) {
    $conn->rollback();
    json_out(['success' => false, 'message' => 'Gagal memperbarui status.'], 500);
}

// Satu pembayaran = satu struk; cek dulu agar tidak dobel.
$cek = $conn->prepare("SELECT id FROM struk WHERE pembayaran_id = ? LIMIT 1");
$cek->bind_param('i', $bayar['id']);
$cek->execute();
if (!$cek->get_result()->fetch_assoc()) {
    $nomorStruk = 'STR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $noInvoice  = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $detail = json_encode([
        'kode_transaksi' => $bayar['kode_transaksi'],
        'booking_id'     => (int)$bayar['booking_id'],
        'total'          => (float)$bayar['gross_amount'],
        'paid_at'        => date('Y-m-d H:i:s'),
        'via'            => 'doku',
        'channel'        => (string)($data['service']['id'] ?? ($data['channel']['id'] ?? '')),
    ]);
    $s = $conn->prepare("INSERT INTO struk (pembayaran_id, nomor_struk, no_invoice, detail_json) VALUES (?, ?, ?, ?)");
    $s->bind_param('isss', $bayar['id'], $nomorStruk, $noInvoice, $detail);
    if (!$s->execute()) {
        $conn->rollback();
        json_out(['success' => false, 'message' => 'Gagal membuat struk.'], 500);
    }
}

$conn->commit();
catat_log((int)$bayar['user_id'], 'pembayaran_lunas_doku', $invoice);
json_out(['success' => true, 'message' => 'Pembayaran ' . $invoice . ' dinyatakan lunas.']);
