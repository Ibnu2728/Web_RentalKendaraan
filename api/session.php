<?php
session_start();
header('Content-Type: application/json');

echo json_encode([
    'logged_in' => isset($_SESSION['user_id']),
    'role'      => $_SESSION['role'] ?? null,
    'nama'      => $_SESSION['nama'] ?? null,
    'mitra_id'  => $_SESSION['mitra_id'] ?? null,
    'user_id'   => $_SESSION['user_id'] ?? null
]);
?>