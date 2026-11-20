<?php
// Katalog publik (tanpa login).
//   GET katalog.php                     -> daftar mobil
//   GET katalog.php?jenis=lepas_kunci   -> hanya yang punya harga lepas kunci (atau dengan_supir)
//   GET katalog.php?id=5                -> detail 1 mobil
require 'config.php';

// Daftar paket sewa (data referensi untuk halaman checkout / ketentuan).
if (isset($_GET['paket'])) {
    $result = $conn->query("SELECT id, kode, nama_paket, deskripsi, butuh_ktp, butuh_sipa, deposit_default, tarif_supir, durasi_supir_jam FROM paket_sewa ORDER BY id");
    $list = [];
    while ($row = $result->fetch_assoc()) $list[] = $row;
    json_out(['success' => true, 'data' => $list]);
}

$base = "SELECT m.id, m.nama_mobil, m.merk, m.plat_nomor, m.tahun, m.transmisi, m.kapasitas,
                m.harga_lepas_kunci, m.harga_dengan_supir, m.deposit,
                m.foto_utama, m.foto_360, m.deskripsi, m.status,
                mt.nama_usaha
         FROM mobil m
         JOIN mitra mt ON m.mitra_id = mt.id
         WHERE m.status = 'tersedia' AND mt.status_verifikasi = 'approved'";

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare($base . " AND m.id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        json_out(['success' => false, 'message' => 'Mobil tidak ditemukan atau tidak tersedia.'], 404);
    }
    json_out(['success' => true, 'data' => $row]);
}

$sql = $base;
$jenis = $_GET['jenis'] ?? '';
if ($jenis === 'lepas_kunci')  $sql .= " AND m.harga_lepas_kunci > 0";
if ($jenis === 'dengan_supir') $sql .= " AND m.harga_dengan_supir > 0";
$sql .= " ORDER BY m.created_at DESC, m.id DESC";

$result = $conn->query($sql);
$list = [];
while ($row = $result->fetch_assoc()) $list[] = $row;

json_out(['success' => true, 'data' => $list]);
