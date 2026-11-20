<?php
// Guard untuk endpoint yang butuh login / role tertentu.
require_once __DIR__ . '/config.php';

function requireLogin(): array {
    if (empty($_SESSION['user_id'])) {
        json_out(['success' => false, 'message' => 'Silakan login terlebih dahulu.'], 401);
    }
    return [
        'user_id' => (int)$_SESSION['user_id'],
        'role'    => $_SESSION['role'] ?? null,
    ];
}

function requireRole(string ...$roles): array {
    $u = requireLogin();
    if (!in_array($u['role'], $roles, true)) {
        json_out(['success' => false, 'message' => 'Anda tidak memiliki akses.'], 403);
    }
    return $u;
}

function requireMitra(): array {
    $u = requireRole('mitra');
    if (empty($_SESSION['mitra_id'])) {
        json_out(['success' => false, 'message' => 'Profil mitra tidak ditemukan.'], 403);
    }
    $u['mitra_id'] = (int)$_SESSION['mitra_id'];
    return $u;
}
