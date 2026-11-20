<?php
// CRUD mobil milik mitra yang sedang login.
require 'auth_check.php';

$method = $_SERVER['REQUEST_METHOD'];
$mitra  = requireMitra();
$mitraId = $mitra['mitra_id'];

$uploadDir = __DIR__ . '/../uploads/mobil/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

const STATUS_VALID    = ['tersedia', 'disewa', 'nonaktif'];
const TRANSMISI_VALID = ['manual', 'matic'];

/** Baca body PUT/DELETE: mendukung JSON maupun x-www-form-urlencoded. */
function readBody(): array {
    $raw = file_get_contents('php://input');
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'application/json') !== false) {
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }
    parse_str($raw, $d);
    return $d;
}

/** Simpan gambar upload. Return [true, namaFile|null] atau [false, pesanError]. */
function saveImage(string $field, string $prefix, string $dir): array {
    if (empty($_FILES[$field]['name'])) return [true, null];
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return [false, "Upload $field gagal (kode {$f['error']})."];
    if ($f['size'] > 5 * 1024 * 1024)  return [false, "Ukuran $field maksimal 5MB."];

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $map  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($map[$mime])) return [false, "Format $field harus JPG/PNG/WEBP."];

    $name = $prefix . bin2hex(random_bytes(8)) . '.' . $map[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) return [false, "Gagal menyimpan $field."];
    return [true, $name];
}

function platSudahDipakai(mysqli $conn, string $plat, int $exceptId = 0): bool {
    $s = $conn->prepare("SELECT id FROM mobil WHERE plat_nomor = ? AND id <> ?");
    $s->bind_param('si', $plat, $exceptId);
    $s->execute();
    return (bool)$s->get_result()->fetch_assoc();
}

// ==================== GET ====================
if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT * FROM mobil WHERE id = ? AND mitra_id = ?");
        $stmt->bind_param('ii', $id, $mitraId);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        if (!$data) json_out(['success' => false, 'message' => 'Mobil tidak ditemukan.'], 404);
        json_out(['success' => true, 'data' => $data]);
    }

    $stmt = $conn->prepare("SELECT * FROM mobil WHERE mitra_id = ? ORDER BY created_at DESC, id DESC");
    $stmt->bind_param('i', $mitraId);
    $stmt->execute();
    $list = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $list[] = $row;
    json_out(['success' => true, 'data' => $list]);
}

// ==================== POST (TAMBAH) ====================
if ($method === 'POST') {
    $nama      = trim($_POST['nama_mobil'] ?? '');
    $merk      = trim($_POST['merk'] ?? '');
    $plat      = strtoupper(trim($_POST['plat_nomor'] ?? ''));
    $tahun     = (int)($_POST['tahun'] ?? 0) ?: null;
    $transmisi = $_POST['transmisi'] ?? 'manual';
    $kapasitas = (int)($_POST['kapasitas'] ?? 0);
    $hargaLC   = (float)($_POST['harga_lepas_kunci'] ?? 0);
    $hargaSP   = (float)($_POST['harga_dengan_supir'] ?? 0);
    $deposit   = (float)($_POST['deposit'] ?? 500000);
    $deskripsi = trim($_POST['deskripsi'] ?? '');

    if ($nama === '' || $plat === '' || $hargaLC <= 0) {
        json_out(['success' => false, 'message' => 'Nama, plat nomor, dan harga lepas kunci wajib diisi.']);
    }
    if (!in_array($transmisi, TRANSMISI_VALID, true)) {
        json_out(['success' => false, 'message' => 'Transmisi tidak valid.']);
    }
    if ($hargaSP < 0 || $deposit < 0) {
        json_out(['success' => false, 'message' => 'Harga dan deposit tidak boleh negatif.']);
    }
    if (platSudahDipakai($conn, $plat)) {
        json_out(['success' => false, 'message' => "Plat nomor $plat sudah terdaftar."]);
    }

    [$ok, $fotoUtama] = saveImage('foto_utama', 'mobil_', $uploadDir);
    if (!$ok) json_out(['success' => false, 'message' => $fotoUtama]);
    [$ok, $foto360] = saveImage('foto_360', 'mobil360_', $uploadDir);
    if (!$ok) json_out(['success' => false, 'message' => $foto360]);

    $stmt = $conn->prepare(
        "INSERT INTO mobil
         (mitra_id, nama_mobil, merk, plat_nomor, tahun, transmisi, kapasitas,
          harga_lepas_kunci, harga_dengan_supir, deposit, foto_utama, foto_360, deskripsi, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'tersedia')"
    );
    //            i  s  s  s  i  s  i  d  d  d  s  s  s
    $stmt->bind_param('isssisidddsss',
        $mitraId, $nama, $merk, $plat, $tahun, $transmisi, $kapasitas,
        $hargaLC, $hargaSP, $deposit, $fotoUtama, $foto360, $deskripsi
    );

    if ($stmt->execute()) {
        json_out(['success' => true, 'message' => 'Mobil berhasil ditambahkan.', 'mobil_id' => $stmt->insert_id]);
    }
    json_out(['success' => false, 'message' => 'Gagal menyimpan: ' . $stmt->error]);
}

// ==================== PUT (EDIT) ====================
if ($method === 'PUT') {
    $data = readBody();
    $id = (int)($data['id'] ?? 0);

    $s = $conn->prepare("SELECT * FROM mobil WHERE id = ? AND mitra_id = ?");
    $s->bind_param('ii', $id, $mitraId);
    $s->execute();
    $cur = $s->get_result()->fetch_assoc();
    if (!$cur) json_out(['success' => false, 'message' => 'Mobil tidak ditemukan / bukan milik Anda.'], 404);

    // Field yang tidak dikirim tetap memakai nilai lama (tidak ditimpa NULL).
    $nama      = trim($data['nama_mobil']  ?? $cur['nama_mobil']);
    $merk      = trim($data['merk']        ?? (string)$cur['merk']);
    $plat      = strtoupper(trim($data['plat_nomor'] ?? $cur['plat_nomor']));
    $tahun     = isset($data['tahun']) ? ((int)$data['tahun'] ?: null) : ($cur['tahun'] !== null ? (int)$cur['tahun'] : null);
    $transmisi = $data['transmisi']    ?? $cur['transmisi'];
    $kapasitas = (int)($data['kapasitas'] ?? $cur['kapasitas']);
    $hargaLC   = (float)($data['harga_lepas_kunci']  ?? $cur['harga_lepas_kunci']);
    $hargaSP   = (float)($data['harga_dengan_supir'] ?? $cur['harga_dengan_supir']);
    $deposit   = (float)($data['deposit']   ?? $cur['deposit']);
    $deskripsi = trim($data['deskripsi']    ?? (string)$cur['deskripsi']);
    $status    = $data['status']            ?? $cur['status'];

    if ($nama === '' || $plat === '' || $hargaLC <= 0) {
        json_out(['success' => false, 'message' => 'Nama, plat nomor, dan harga lepas kunci wajib diisi.']);
    }
    if (!in_array($status, STATUS_VALID, true))       json_out(['success' => false, 'message' => 'Status tidak valid.']);
    if (!in_array($transmisi, TRANSMISI_VALID, true)) json_out(['success' => false, 'message' => 'Transmisi tidak valid.']);
    if ($hargaSP < 0 || $deposit < 0)                 json_out(['success' => false, 'message' => 'Harga dan deposit tidak boleh negatif.']);
    if (platSudahDipakai($conn, $plat, $id))          json_out(['success' => false, 'message' => "Plat nomor $plat sudah dipakai mobil lain."]);

    $stmt = $conn->prepare(
        "UPDATE mobil SET nama_mobil=?, merk=?, plat_nomor=?, tahun=?, transmisi=?, kapasitas=?,
                harga_lepas_kunci=?, harga_dengan_supir=?, deposit=?, deskripsi=?, status=?
         WHERE id=? AND mitra_id=?"
    );
    //            s  s  s  i  s  i  d  d  d  s  s  i  i
    $stmt->bind_param('sssisidddssii',
        $nama, $merk, $plat, $tahun, $transmisi, $kapasitas,
        $hargaLC, $hargaSP, $deposit, $deskripsi, $status, $id, $mitraId
    );

    if ($stmt->execute()) json_out(['success' => true, 'message' => 'Mobil berhasil diperbarui.']);
    json_out(['success' => false, 'message' => 'Gagal memperbarui: ' . $stmt->error]);
}

// ==================== DELETE (NONAKTIFKAN) ====================
if ($method === 'DELETE') {
    $data = readBody();
    $id = (int)($data['id'] ?? 0);

    $s = $conn->prepare("SELECT id FROM mobil WHERE id = ? AND mitra_id = ?");
    $s->bind_param('ii', $id, $mitraId);
    $s->execute();
    if (!$s->get_result()->fetch_assoc()) {
        json_out(['success' => false, 'message' => 'Mobil tidak ditemukan / bukan milik Anda.'], 404);
    }

    // Jangan nonaktifkan kalau masih ada sewa berjalan / sudah dikonfirmasi ke depan.
    $c = $conn->prepare("SELECT COUNT(*) AS n FROM booking
                         WHERE mobil_id = ? AND status IN ('dikonfirmasi','aktif') AND tgl_selesai >= CURDATE()");
    $c->bind_param('i', $id);
    $c->execute();
    if ((int)$c->get_result()->fetch_assoc()['n'] > 0) {
        json_out(['success' => false, 'message' => 'Mobil masih punya booking aktif/terkonfirmasi, tidak bisa dinonaktifkan.']);
    }

    $stmt = $conn->prepare("UPDATE mobil SET status='nonaktif' WHERE id=? AND mitra_id=?");
    $stmt->bind_param('ii', $id, $mitraId);
    if ($stmt->execute()) json_out(['success' => true, 'message' => 'Mobil dinonaktifkan dari katalog.']);
    json_out(['success' => false, 'message' => $stmt->error]);
}

json_out(['success' => false, 'message' => 'Metode tidak didukung.'], 405);
