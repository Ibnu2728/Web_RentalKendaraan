<?php
// Laporan pendapatan harian (khusus admin).
require 'auth_check.php';
requireRole('admin');

$dari   = $_GET['dari']   ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-t');

foreach ([$dari, $sampai] as $t) {
    $d = DateTime::createFromFormat('Y-m-d', $t);
    if (!$d || $d->format('Y-m-d') !== $t) {
        json_out(['success' => false, 'message' => 'Format tanggal harus YYYY-MM-DD.'], 400);
    }
}

$stmt = $conn->prepare("SELECT DATE(p.paid_at) AS tanggal, SUM(p.gross_amount) AS total
        FROM pembayaran p
        WHERE p.status = 'settlement' AND DATE(p.paid_at) BETWEEN ? AND ?
        GROUP BY DATE(p.paid_at)
        ORDER BY tanggal ASC");
$stmt->bind_param('ss', $dari, $sampai);
$stmt->execute();
$result = $stmt->get_result();

$labels = [];
$values = [];
while ($row = $result->fetch_assoc()) {
    $labels[] = $row['tanggal'];
    $values[] = (int)$row['total'];
}

json_out([
    'success'    => true,
    'labels'     => $labels,
    'pendapatan' => $values,
    'total'      => array_sum($values),
]);
