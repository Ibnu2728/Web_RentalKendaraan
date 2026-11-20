<?php
// Area admin — satu endpoint untuk semua halaman kelola.
//   GET  admin.php?action=dashboard            -> statistik nyata + booking terbaru
//   GET  admin.php?action=users                -> semua akun
//   POST admin.php?action=user_status          -> aktif/nonaktifkan akun (id, status)
//   GET  admin.php?action=mitra                -> semua mitra (+ data akunnya)
//   POST admin.php?action=verifikasi_mitra     -> setujui/tolak mitra (mitra_id, keputusan)
//   GET  admin.php?action=mobil                -> semua mobil (+ nama mitra)
//   POST admin.php?action=mobil_status         -> ubah status mobil (id, status)
//   GET  admin.php?action=booking              -> semua booking (+ penyewa, mobil, paket)
//   POST admin.php?action=booking_status       -> ubah status booking (booking_id, status)
//   GET  admin.php?action=pembayaran           -> semua pembayaran (+ booking & penyewa)
//   POST admin.php?action=verifikasi_pembayaran-> lunasi/batalkan tagihan (pembayaran_id, keputusan)
//   GET  admin.php?action=laporan              -> pemasukan & pengeluaran per rentang (?dari&sampai)
//   GET  admin.php?action=pengeluaran          -> daftar pengeluaran
//   POST admin.php?action=pengeluaran_tambah   -> catat pengeluaran
//   POST admin.php?action=pengeluaran_hapus    -> hapus pengeluaran (id)
//   GET  admin.php?action=profil               -> data admin yang login
//   POST admin.php?action=profil               -> ubah nama admin
//   POST admin.php?action=ganti_password       -> ubah password admin sendiri
require 'auth_check.php';

$admin = requireRole('admin');
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function daftar(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $list = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $list[] = $row;
    return $list;
}

if ($action === 'dashboard' && $method === 'GET') {
    $satu = function (string $sql) use ($conn) {
        $r = $conn->query($sql)->fetch_assoc();
        return $r ? array_values($r)[0] : 0;
    };
    $stats = [
        'total_booking'        => (int)$satu("SELECT COUNT(*) FROM booking"),
        'total_mobil'          => (int)$satu("SELECT COUNT(*) FROM mobil"),
        'mitra_aktif'          => (int)$satu("SELECT COUNT(*) FROM mitra WHERE status_verifikasi = 'approved'"),
        'mitra_pending'        => (int)$satu("SELECT COUNT(*) FROM mitra WHERE status_verifikasi = 'pending'"),
        'total_user'           => (int)$satu("SELECT COUNT(*) FROM users WHERE role = 'user'"),
        'pendapatan_bulan_ini' => (float)$satu("SELECT COALESCE(SUM(gross_amount),0) FROM pembayaran WHERE status = 'settlement' AND DATE_FORMAT(paid_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')"),
    ];
    $terbaru = daftar($conn, "SELECT b.kode_booking, u.nama AS penyewa, m.nama_mobil,
            ps.kode AS jenis_sewa, b.total_bayar, b.status
        FROM booking b
        JOIN users u ON u.id = b.user_id
        JOIN mobil m ON m.id = b.mobil_id
        JOIN paket_sewa ps ON ps.id = b.paket_id
        ORDER BY b.id DESC LIMIT 10");
    json_out(['success' => true, 'stats' => $stats, 'booking_terbaru' => $terbaru]);
}

if ($action === 'users' && $method === 'GET') {
    json_out(['success' => true, 'data' => daftar($conn,
        "SELECT id, nama, email, no_hp, role, status, created_at FROM users ORDER BY id DESC")]);
}

if ($action === 'user_status' && $method === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (!in_array($status, ['aktif', 'nonaktif'], true)) {
        json_out(['success' => false, 'message' => 'Status tidak valid.']);
    }
    if ($id === $admin['user_id']) {
        json_out(['success' => false, 'message' => 'Tidak bisa mengubah status akun sendiri.']);
    }
    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $id);
    if ($stmt->execute() && $stmt->affected_rows === 1) {
        catat_log($admin['user_id'], 'admin_user_status', "user $id -> $status");
        json_out(['success' => true, 'message' => 'Status akun diperbarui.']);
    }
    json_out(['success' => false, 'message' => 'Akun tidak ditemukan.'], 404);
}

if ($action === 'mitra' && $method === 'GET') {
    json_out(['success' => true, 'data' => daftar($conn,
        "SELECT mt.id, mt.nama_usaha, mt.no_sipa, mt.status_verifikasi,
                mt.rekening_bank, mt.no_rekening, mt.nama_rekening,
                u.id AS user_id, u.nama, u.email, u.no_hp, u.status AS status_akun,
                (SELECT COUNT(*) FROM mobil mb WHERE mb.mitra_id = mt.id) AS jumlah_mobil
         FROM mitra mt JOIN users u ON u.id = mt.user_id ORDER BY mt.id DESC")]);
}

if ($action === 'verifikasi_mitra' && $method === 'POST') {
    $mitraId = (int)($_POST['mitra_id'] ?? 0);
    $keputusan = $_POST['keputusan'] ?? '';
    if (!in_array($keputusan, ['approved', 'rejected'], true)) {
        json_out(['success' => false, 'message' => 'Keputusan tidak valid.']);
    }
    $stmt = $conn->prepare("UPDATE mitra SET status_verifikasi = ? WHERE id = ? AND status_verifikasi = 'pending'");
    $stmt->bind_param('si', $keputusan, $mitraId);
    if ($stmt->execute() && $stmt->affected_rows === 1) {
        catat_log($admin['user_id'], 'admin_verifikasi_mitra', "mitra $mitraId -> $keputusan");
        json_out(['success' => true, 'message' => 'Mitra ' . ($keputusan === 'approved' ? 'disetujui.' : 'ditolak.')]);
    }
    json_out(['success' => false, 'message' => 'Mitra tidak ditemukan atau sudah diverifikasi.'], 404);
}

if ($action === 'mobil' && $method === 'GET') {
    json_out(['success' => true, 'data' => daftar($conn,
        "SELECT m.*, mt.nama_usaha FROM mobil m JOIN mitra mt ON mt.id = m.mitra_id ORDER BY m.id DESC")]);
}

if ($action === 'mobil_status' && $method === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (!in_array($status, ['tersedia', 'disewa', 'nonaktif'], true)) {
        json_out(['success' => false, 'message' => 'Status tidak valid.']);
    }
    $stmt = $conn->prepare("UPDATE mobil SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $id);
    if ($stmt->execute() && $stmt->affected_rows === 1) {
        catat_log($admin['user_id'], 'admin_mobil_status', "mobil $id -> $status");
        json_out(['success' => true, 'message' => 'Status mobil diperbarui.']);
    }
    json_out(['success' => false, 'message' => 'Mobil tidak ditemukan.'], 404);
}

const ADMIN_BOOKING_SELECT = "SELECT b.id, b.kode_booking, b.tgl_mulai, b.tgl_selesai, b.total_hari,
        b.total_bayar, b.status, b.created_at, ps.kode AS jenis_sewa,
        u.nama AS penyewa, m.nama_mobil, mt.nama_usaha,
        (SELECT p.status FROM pembayaran p WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1) AS status_bayar
    FROM booking b
    JOIN users u ON u.id = b.user_id
    JOIN mobil m ON m.id = b.mobil_id
    JOIN mitra mt ON mt.id = m.mitra_id
    JOIN paket_sewa ps ON ps.id = b.paket_id";

if ($action === 'booking' && $method === 'GET') {
    json_out(['success' => true, 'data' => daftar($conn, ADMIN_BOOKING_SELECT . " ORDER BY b.id DESC")]);
}

if ($action === 'booking_status' && $method === 'POST') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $statusBaru = $_POST['status'] ?? '';
    $transisi = [
        'dikonfirmasi' => ['pending'],
        'aktif'        => ['dikonfirmasi'],
        'selesai'      => ['aktif', 'dikonfirmasi'],
        'batal'        => ['pending', 'dikonfirmasi', 'aktif'],
    ];
    if (!isset($transisi[$statusBaru])) {
        json_out(['success' => false, 'message' => 'Status tujuan tidak valid.']);
    }
    $q = $conn->prepare("SELECT kode_booking, status FROM booking WHERE id = ?");
    $q->bind_param('i', $bookingId);
    $q->execute();
    $b = $q->get_result()->fetch_assoc();
    if (!$b) {
        json_out(['success' => false, 'message' => 'Booking tidak ditemukan.'], 404);
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
        catat_log($admin['user_id'], 'admin_booking_status', $b['kode_booking'] . ' -> ' . $statusBaru);
        json_out(['success' => true, 'message' => 'Status booking ' . $b['kode_booking'] . ' sekarang "' . $statusBaru . '".']);
    }
    $conn->rollback();
    json_out(['success' => false, 'message' => 'Gagal mengubah status booking.']);
}

if ($action === 'pembayaran' && $method === 'GET') {
    json_out(['success' => true, 'data' => daftar($conn,
        "SELECT p.id, p.kode_transaksi, p.metode, p.gross_amount, p.status, p.va_number, p.paid_at, p.created_at,
                b.kode_booking, u.nama AS penyewa, s.nomor_struk
         FROM pembayaran p
         JOIN booking b ON b.id = p.booking_id
         JOIN users u ON u.id = b.user_id
         LEFT JOIN struk s ON s.pembayaran_id = p.id
         ORDER BY p.id DESC")]);
}

if ($action === 'verifikasi_pembayaran' && $method === 'POST') {
    $pembayaranId = (int)($_POST['pembayaran_id'] ?? 0);
    $keputusan = $_POST['keputusan'] ?? '';
    if (!in_array($keputusan, ['settlement', 'cancel'], true)) {
        json_out(['success' => false, 'message' => 'Keputusan tidak valid.']);
    }
    $q = $conn->prepare("SELECT p.*, b.kode_booking FROM pembayaran p JOIN booking b ON b.id = p.booking_id WHERE p.id = ?");
    $q->bind_param('i', $pembayaranId);
    $q->execute();
    $p = $q->get_result()->fetch_assoc();
    if (!$p || $p['status'] !== 'pending') {
        json_out(['success' => false, 'message' => 'Tagihan tidak ditemukan atau sudah diproses.'], 404);
    }

    $conn->begin_transaction();
    if ($keputusan === 'settlement') {
        $a = $conn->prepare("UPDATE pembayaran SET status = 'settlement', paid_at = NOW() WHERE id = ? AND status = 'pending'");
        $a->bind_param('i', $pembayaranId);
        $b = $conn->prepare("UPDATE booking SET status = 'dikonfirmasi' WHERE id = ? AND status = 'pending'");
        $b->bind_param('i', $p['booking_id']);
        $ok = $a->execute() && $b->execute() && $a->affected_rows === 1 && $b->affected_rows === 1;
        if ($ok) {
            $nomorStruk = 'STR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $noInvoice  = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $detail = json_encode([
                'kode_transaksi' => $p['kode_transaksi'],
                'booking_id'     => (int)$p['booking_id'],
                'total'          => (float)$p['gross_amount'],
                'paid_at'        => date('Y-m-d H:i:s'),
                'via'            => 'verifikasi_admin',
            ]);
            $s = $conn->prepare("INSERT INTO struk (pembayaran_id, nomor_struk, no_invoice, detail_json) VALUES (?, ?, ?, ?)");
            $s->bind_param('isss', $pembayaranId, $nomorStruk, $noInvoice, $detail);
            $ok = $s->execute();
        }
    } else {
        $a = $conn->prepare("UPDATE pembayaran SET status = 'cancel' WHERE id = ? AND status = 'pending'");
        $a->bind_param('i', $pembayaranId);
        $ok = $a->execute() && $a->affected_rows === 1;
    }

    if ($ok) {
        $conn->commit();
        catat_log($admin['user_id'], 'admin_verifikasi_pembayaran', $p['kode_booking'] . ' -> ' . $keputusan);
        json_out(['success' => true, 'message' => 'Pembayaran ' . $p['kode_booking'] . ' ' . ($keputusan === 'settlement' ? 'dinyatakan lunas.' : 'dibatalkan.')]);
    }
    $conn->rollback();
    json_out(['success' => false, 'message' => 'Gagal memproses verifikasi pembayaran.']);
}

if ($action === 'laporan' && $method === 'GET') {
    $dari   = $_GET['dari']   ?? date('Y-m-01');
    $sampai = $_GET['sampai'] ?? date('Y-m-d');
    foreach ([$dari, $sampai] as $t) {
        $d = DateTime::createFromFormat('Y-m-d', $t);
        if (!$d || $d->format('Y-m-d') !== $t) {
            json_out(['success' => false, 'message' => 'Format tanggal harus YYYY-MM-DD.'], 400);
        }
    }
    $masuk = daftar($conn, "SELECT DATE(paid_at) AS tanggal, SUM(gross_amount) AS total
        FROM pembayaran WHERE status = 'settlement' AND DATE(paid_at) BETWEEN ? AND ?
        GROUP BY DATE(paid_at) ORDER BY tanggal", 'ss', [$dari, $sampai]);
    $keluar = daftar($conn, "SELECT tanggal, SUM(jumlah) AS total
        FROM pengeluaran WHERE tanggal BETWEEN ? AND ?
        GROUP BY tanggal ORDER BY tanggal", 'ss', [$dari, $sampai]);
    $totalMasuk  = array_sum(array_map(fn($r) => (float)$r['total'], $masuk));
    $totalKeluar = array_sum(array_map(fn($r) => (float)$r['total'], $keluar));
    json_out([
        'success' => true,
        'pemasukan' => $masuk,
        'pengeluaran' => $keluar,
        'total_pemasukan' => $totalMasuk,
        'total_pengeluaran' => $totalKeluar,
        'saldo' => $totalMasuk - $totalKeluar,
    ]);
}

if ($action === 'pengeluaran' && $method === 'GET') {
    json_out(['success' => true, 'data' => daftar($conn,
        "SELECT pg.*, u.nama AS dicatat_oleh_nama FROM pengeluaran pg
         JOIN users u ON u.id = pg.dicatat_oleh ORDER BY pg.tanggal DESC, pg.id DESC")]);
}

if ($action === 'pengeluaran_tambah' && $method === 'POST') {
    $tanggal   = $_POST['tanggal'] ?? date('Y-m-d');
    $kategori  = trim($_POST['kategori'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    $jumlah    = (float)($_POST['jumlah'] ?? 0);
    $d = DateTime::createFromFormat('Y-m-d', $tanggal);
    if (!$d || $d->format('Y-m-d') !== $tanggal || $kategori === '' || $jumlah <= 0) {
        json_out(['success' => false, 'message' => 'Tanggal, kategori, dan jumlah (> 0) wajib diisi dengan benar.'], 400);
    }
    $stmt = $conn->prepare("INSERT INTO pengeluaran (tanggal, kategori, keterangan, jumlah, dicatat_oleh) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('sssdi', $tanggal, $kategori, $keterangan, $jumlah, $admin['user_id']);
    if ($stmt->execute()) {
        catat_log($admin['user_id'], 'admin_pengeluaran_tambah', $kategori . ' Rp' . $jumlah);
        json_out(['success' => true, 'message' => 'Pengeluaran tercatat.']);
    }
    json_out(['success' => false, 'message' => 'Gagal mencatat pengeluaran: ' . $stmt->error]);
}

if ($action === 'pengeluaran_hapus' && $method === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM pengeluaran WHERE id = ?");
    $stmt->bind_param('i', $id);
    if ($stmt->execute() && $stmt->affected_rows === 1) {
        catat_log($admin['user_id'], 'admin_pengeluaran_hapus', "pengeluaran $id");
        json_out(['success' => true, 'message' => 'Pengeluaran dihapus.']);
    }
    json_out(['success' => false, 'message' => 'Pengeluaran tidak ditemukan.'], 404);
}

if ($action === 'profil' && $method === 'GET') {
    $q = $conn->prepare("SELECT id, nama, email, no_hp, alamat, created_at FROM users WHERE id = ?");
    $q->bind_param('i', $admin['user_id']);
    $q->execute();
    json_out(['success' => true, 'data' => $q->get_result()->fetch_assoc()]);
}

if ($action === 'profil' && $method === 'POST') {
    $nama = trim($_POST['nama'] ?? '');
    $noHp = trim($_POST['no_hp'] ?? '');
    if ($nama === '') {
        json_out(['success' => false, 'message' => 'Nama wajib diisi.'], 400);
    }
    $stmt = $conn->prepare("UPDATE users SET nama = ?, no_hp = ? WHERE id = ?");
    $stmt->bind_param('ssi', $nama, $noHp, $admin['user_id']);
    if ($stmt->execute()) {
        $_SESSION['nama'] = $nama;
        json_out(['success' => true, 'message' => 'Profil diperbarui.']);
    }
    json_out(['success' => false, 'message' => 'Gagal memperbarui profil.']);
}

if ($action === 'ganti_password' && $method === 'POST') {
    $lama = (string)($_POST['password_lama'] ?? '');
    $baru = (string)($_POST['password_baru'] ?? '');
    if (strlen($baru) < 6) {
        json_out(['success' => false, 'message' => 'Password baru minimal 6 karakter.'], 400);
    }
    $q = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $q->bind_param('i', $admin['user_id']);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    if (!$row || !password_verify($lama, $row['password'])) {
        json_out(['success' => false, 'message' => 'Password lama salah.'], 400);
    }
    $hash = password_hash($baru, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->bind_param('si', $hash, $admin['user_id']);
    if ($stmt->execute()) {
        catat_log($admin['user_id'], 'admin_ganti_password', '');
        json_out(['success' => true, 'message' => 'Password berhasil diganti.']);
    }
    json_out(['success' => false, 'message' => 'Gagal mengganti password.']);
}

json_out(['success' => false, 'message' => 'Action tidak dikenal.'], 400);
