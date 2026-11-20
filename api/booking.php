<?php
require 'auth_check.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}
$u = requireRole('user');

$mobilId = (int)($_POST['mobil_id'] ?? 0);
$jenis   = $_POST['jenis_sewa'] ?? '';
$mulai   = $_POST['tgl_mulai'] ?? '';
$selesai = $_POST['tgl_selesai'] ?? '';

if (!in_array($jenis, ['lepas_kunci', 'dengan_supir'], true)) {
    json_out(['success' => false, 'message' => 'Jenis sewa tidak valid.']);
}

// Validasi tanggal (format Y-m-d, tidak di masa lalu, selesai >= mulai)
$d1 = DateTime::createFromFormat('Y-m-d', $mulai);
$d2 = DateTime::createFromFormat('Y-m-d', $selesai);
if (!$d1 || $d1->format('Y-m-d') !== $mulai || !$d2 || $d2->format('Y-m-d') !== $selesai) {
    json_out(['success' => false, 'message' => 'Format tanggal tidak valid.']);
}
$today = new DateTime('today');
if ($d1 < $today)  json_out(['success' => false, 'message' => 'Tanggal mulai tidak boleh di masa lalu.']);
if ($d2 < $d1)     json_out(['success' => false, 'message' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.']);

$totalHari = max(1, (int)$d1->diff($d2)->days);

// Ambil mobil (harus tersedia & mitra sudah diverifikasi)
$q = $conn->prepare("SELECT m.* FROM mobil m JOIN mitra mt ON m.mitra_id = mt.id
                     WHERE m.id = ? AND m.status = 'tersedia' AND mt.status_verifikasi = 'approved'");
$q->bind_param('i', $mobilId);
$q->execute();
$mobil = $q->get_result()->fetch_assoc();
if (!$mobil) json_out(['success' => false, 'message' => 'Mobil tidak ditemukan atau tidak tersedia.'], 404);

$hargaPerHari = (float)($jenis === 'lepas_kunci' ? $mobil['harga_lepas_kunci'] : $mobil['harga_dengan_supir']);
if ($hargaPerHari <= 0) {
    json_out(['success' => false, 'message' => 'Mobil ini tidak melayani jenis sewa tersebut.']);
}
$deposit    = $jenis === 'lepas_kunci' ? (float)$mobil['deposit'] : 0.0;
$totalBayar = $hargaPerHari * $totalHari + $deposit;

// Cek bentrok jadwal. Booking 'pending' hanya menahan mobil selama 1 jam (batas waktu bayar).
$c = $conn->prepare("SELECT COUNT(*) AS n FROM booking
                     WHERE mobil_id = ?
                       AND tgl_mulai <= ? AND tgl_selesai >= ?
                       AND (status IN ('dikonfirmasi','aktif')
                            OR (status = 'pending' AND created_at > NOW() - INTERVAL 1 HOUR))");
$c->bind_param('iss', $mobilId, $selesai, $mulai);
$c->execute();
if ((int)$c->get_result()->fetch_assoc()['n'] > 0) {
    json_out(['success' => false, 'message' => 'Mobil sudah dipesan pada tanggal tersebut. Pilih tanggal lain.']);
}

$kode = 'BK-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

$stmt = $conn->prepare("INSERT INTO booking
    (kode_booking, user_id, mobil_id, jenis_sewa, tgl_mulai, tgl_selesai, total_hari, harga_per_hari, deposit, total_bayar, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
//                    s  i  i  s  s  s  i  d  d  d
$stmt->bind_param('siisssiddd', $kode, $u['user_id'], $mobilId, $jenis, $mulai, $selesai,
                  $totalHari, $hargaPerHari, $deposit, $totalBayar);

if ($stmt->execute()) {
    json_out(['success' => true, 'booking_id' => $stmt->insert_id, 'kode' => $kode, 'total' => $totalBayar]);
}
json_out(['success' => false, 'message' => 'Gagal membuat booking: ' . $stmt->error]);
