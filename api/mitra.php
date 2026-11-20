<?php
// Area mitra (pemilik mobil).
//   GET  mitra.php?action=dashboard   -> ringkasan + booking masuk terbaru
//   GET  mitra.php?action=booking     -> semua booking untuk mobil milik mitra
//   POST mitra.php?action=status      -> ubah status booking (booking_id, status)
//   GET  mitra.php?action=pendapatan  -> pendapatan per hari (?dari&sampai) + total
//   GET  mitra.php?action=profil      -> profil usaha + akun
//   POST mitra.php?action=profil      -> perbarui profil usaha & rekening
// Status booking yang boleh diubah mitra: dikonfirmasi -> aktif (serah terima),
// aktif -> selesai (unit kembali), pending/dikonfirmasi -> batal (ditolak).
require 'auth_check.php';

$m = requireMitra();
$mitraId = $m['mitra_id'];
$action = $_GET['action'] ?? '';

const BOOKING_MITRA_SELECT = "SELECT b.id, b.kode_booking, b.tgl_mulai, b.tgl_selesai, b.total_hari,
        b.harga_per_hari, b.deposit, b.total_bayar, b.status, b.created_at,
        ps.kode AS jenis_sewa, ps.nama_paket,
        mb.nama_mobil, mb.plat_nomor, u.nama AS penyewa, u.no_hp AS hp_penyewa,
        (SELECT p.status FROM pembayaran p WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1) AS status_bayar
    FROM booking b
    JOIN mobil mb ON mb.id = b.mobil_id
    JOIN users u ON u.id = b.user_id
    JOIN paket_sewa ps ON ps.id = b.paket_id
    WHERE mb.mitra_id = ?";

if ($action === 'dashboard' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = $conn->prepare("SELECT
            (SELECT COUNT(*) FROM mobil WHERE mitra_id = ?) AS total_mobil,
            (SELECT COUNT(*) FROM booking b JOIN mobil mb ON mb.id = b.mobil_id
              WHERE mb.mitra_id = ? AND b.status IN ('dikonfirmasi','aktif')) AS booking_berjalan,
            (SELECT COALESCE(SUM(p.gross_amount), 0) FROM pembayaran p
              JOIN booking b ON b.id = p.booking_id
              JOIN mobil mb ON mb.id = b.mobil_id
              WHERE mb.mitra_id = ? AND p.status = 'settlement'
                AND DATE_FORMAT(p.paid_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')) AS pendapatan_bulan_ini");
    $q->bind_param('iii', $mitraId, $mitraId, $mitraId);
    $q->execute();
    $ringkas = $q->get_result()->fetch_assoc();

    $q = $conn->prepare(BOOKING_MITRA_SELECT . " ORDER BY b.id DESC LIMIT 5");
    $q->bind_param('i', $mitraId);
    $q->execute();
    $masuk = [];
    $res = $q->get_result();
    while ($row = $res->fetch_assoc()) $masuk[] = $row;

    json_out(['success' => true, 'ringkasan' => $ringkas, 'booking_masuk' => $masuk]);
}

if ($action === 'booking' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = $conn->prepare(BOOKING_MITRA_SELECT . " ORDER BY b.id DESC");
    $q->bind_param('i', $mitraId);
    $q->execute();
    $list = [];
    $res = $q->get_result();
    while ($row = $res->fetch_assoc()) $list[] = $row;
    json_out(['success' => true, 'data' => $list]);
}

if ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $statusBaru = $_POST['status'] ?? '';

    $transisi = [
        'aktif'   => ['dikonfirmasi'],
        'selesai' => ['aktif'],
        'batal'   => ['pending', 'dikonfirmasi'],
    ];
    if (!isset($transisi[$statusBaru])) {
        json_out(['success' => false, 'message' => 'Status tujuan tidak diizinkan untuk mitra.']);
    }

    $q = $conn->prepare("SELECT b.id, b.kode_booking, b.status FROM booking b
                         JOIN mobil mb ON mb.id = b.mobil_id
                         WHERE b.id = ? AND mb.mitra_id = ?");
    $q->bind_param('ii', $bookingId, $mitraId);
    $q->execute();
    $b = $q->get_result()->fetch_assoc();
    if (!$b) {
        json_out(['success' => false, 'message' => 'Booking tidak ditemukan atau bukan untuk mobil Anda.'], 404);
    }
    if (!in_array($b['status'], $transisi[$statusBaru], true)) {
        json_out(['success' => false, 'message' => 'Booking berstatus "' . $b['status'] . '" tidak bisa diubah menjadi "' . $statusBaru . '".']);
    }

    $conn->begin_transaction();
    $up = $conn->prepare("UPDATE booking SET status = ? WHERE id = ? AND status = ?");
    $up->bind_param('sis', $statusBaru, $bookingId, $b['status']);
    $ok = $up->execute() && $up->affected_rows === 1;
    if ($ok && $statusBaru === 'batal') {
        $p = $conn->prepare("UPDATE pembayaran SET status = 'cancel' WHERE booking_id = ? AND status = 'pending'");
        $p->bind_param('i', $bookingId);
        $ok = $p->execute();
    }
    if ($ok) {
        $conn->commit();
        catat_log($m['user_id'], 'mitra_ubah_status_booking', $b['kode_booking'] . ' -> ' . $statusBaru);
        json_out(['success' => true, 'message' => 'Status booking ' . $b['kode_booking'] . ' sekarang "' . $statusBaru . '".']);
    }
    $conn->rollback();
    json_out(['success' => false, 'message' => 'Gagal mengubah status booking.']);
}

if ($action === 'pendapatan' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $dari   = $_GET['dari']   ?? date('Y-m-01');
    $sampai = $_GET['sampai'] ?? date('Y-m-d');
    foreach ([$dari, $sampai] as $t) {
        $d = DateTime::createFromFormat('Y-m-d', $t);
        if (!$d || $d->format('Y-m-d') !== $t) {
            json_out(['success' => false, 'message' => 'Format tanggal harus YYYY-MM-DD.'], 400);
        }
    }
    $q = $conn->prepare("SELECT DATE(p.paid_at) AS tanggal, COUNT(*) AS transaksi, SUM(p.gross_amount) AS total
        FROM pembayaran p
        JOIN booking b ON b.id = p.booking_id
        JOIN mobil mb ON mb.id = b.mobil_id
        WHERE mb.mitra_id = ? AND p.status = 'settlement' AND DATE(p.paid_at) BETWEEN ? AND ?
        GROUP BY DATE(p.paid_at) ORDER BY tanggal ASC");
    $q->bind_param('iss', $mitraId, $dari, $sampai);
    $q->execute();
    $list = [];
    $total = 0;
    $res = $q->get_result();
    while ($row = $res->fetch_assoc()) {
        $list[] = $row;
        $total += (float)$row['total'];
    }
    json_out(['success' => true, 'data' => $list, 'total' => $total]);
}

if ($action === 'profil' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = $conn->prepare("SELECT u.nama, u.email, u.no_hp, u.alamat,
            mt.nama_usaha, mt.no_sipa, mt.status_verifikasi,
            mt.rekening_bank, mt.no_rekening, mt.nama_rekening
        FROM mitra mt JOIN users u ON u.id = mt.user_id
        WHERE mt.id = ?");
    $q->bind_param('i', $mitraId);
    $q->execute();
    $profil = $q->get_result()->fetch_assoc();
    if (!$profil) {
        json_out(['success' => false, 'message' => 'Profil tidak ditemukan.'], 404);
    }
    json_out(['success' => true, 'data' => $profil]);
}

if ($action === 'profil' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $namaUsaha = trim($_POST['nama_usaha'] ?? '');
    $noHp      = trim($_POST['no_hp'] ?? '');
    $alamat    = trim($_POST['alamat'] ?? '');
    $bank      = trim($_POST['rekening_bank'] ?? '');
    $noRek     = trim($_POST['no_rekening'] ?? '');
    $namaRek   = trim($_POST['nama_rekening'] ?? '');
    if ($namaUsaha === '') {
        json_out(['success' => false, 'message' => 'Nama usaha wajib diisi.'], 400);
    }
    $conn->begin_transaction();
    $a = $conn->prepare("UPDATE mitra SET nama_usaha = ?, rekening_bank = ?, no_rekening = ?, nama_rekening = ? WHERE id = ?");
    $a->bind_param('ssssi', $namaUsaha, $bank, $noRek, $namaRek, $mitraId);
    $b = $conn->prepare("UPDATE users SET no_hp = ?, alamat = ? WHERE id = ?");
    $b->bind_param('ssi', $noHp, $alamat, $m['user_id']);
    if ($a->execute() && $b->execute()) {
        $conn->commit();
        json_out(['success' => true, 'message' => 'Profil berhasil diperbarui.']);
    }
    $conn->rollback();
    json_out(['success' => false, 'message' => 'Gagal memperbarui profil.']);
}

json_out(['success' => false, 'message' => 'Action tidak dikenal.'], 400);
