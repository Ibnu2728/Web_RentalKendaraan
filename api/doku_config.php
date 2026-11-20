<?php
// ============================================================
//  Konfigurasi & helper DOKU Checkout (Jokul API).
//  Kredensial dibaca dari api/doku_secret.php (tidak ter-commit).
//  Selama file itu belum ada / belum diisi, doku_aktif() = false dan
//  pembayaran berjalan seperti biasa dalam mode SIMULASI VA.
// ============================================================

function doku_secret(): array {
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/doku_secret.php';
        $cfg = is_file($file) ? (array)require $file : [];
    }
    return $cfg;
}

function doku_aktif(): bool {
    $c = doku_secret();
    return !empty($c['client_id']) && !empty($c['secret_key'])
        && strpos((string)$c['client_id'], 'xxxx') === false;
}

function doku_base_api(): string {
    $mode = doku_secret()['mode'] ?? 'sandbox';
    return $mode === 'production'
        ? 'https://api.doku.com'
        : 'https://api-sandbox.doku.com';
}

function doku_base_url(): string {
    return rtrim((string)(doku_secret()['base_url'] ?? ''), '/');
}

function doku_webhook_target(): string {
    return (string)(doku_secret()['webhook_target'] ?? '/api/doku_webhook.php');
}

function doku_uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** Signature HMAC-SHA256 ala DOKU untuk satu request/notifikasi. */
function doku_signature(string $clientId, string $requestId, string $timestamp, string $target, string $rawBody, string $secret): string {
    $digest = base64_encode(hash('sha256', $rawBody, true));
    $komponen = "Client-Id:$clientId\n"
              . "Request-Id:$requestId\n"
              . "Request-Timestamp:$timestamp\n"
              . "Request-Target:$target\n"
              . "Digest:$digest";
    return 'HMAC-SHA256=' . base64_encode(hash_hmac('sha256', $komponen, $secret, true));
}

/** Header lengkap untuk memanggil API DOKU (termasuk signature). */
function doku_headers(string $target, string $rawBody): array {
    $c = doku_secret();
    $requestId = doku_uuid();
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    return [
        'Content-Type: application/json',
        'Client-Id: ' . $c['client_id'],
        'Request-Id: ' . $requestId,
        'Request-Timestamp: ' . $timestamp,
        'Signature: ' . doku_signature($c['client_id'], $requestId, $timestamp, $target, $rawBody, $c['secret_key']),
    ];
}

/**
 * Minta link halaman pembayaran DOKU Checkout.
 * $payload sesuai format Jokul Checkout (order, payment, customer).
 * Return: ['url' => string] atau ['error' => string].
 */
function doku_buat_link_bayar(array $payload): array {
    $target = '/checkout/v1/payment';
    $body = json_encode($payload);
    $ch = curl_init(doku_base_api() . $target);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => doku_headers($target, $body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($res === false) {
        return ['error' => 'Gagal menghubungi DOKU: ' . $err];
    }
    $json = json_decode((string)$res, true);
    $url = $json['response']['payment']['url'] ?? null;
    if ($code >= 200 && $code < 300 && $url) {
        return ['url' => $url];
    }
    $pesan = $json['error']['message'] ?? ($json['message'] ?? ('HTTP ' . $code));
    return ['error' => 'DOKU menolak permintaan: ' . $pesan];
}

/**
 * Verifikasi signature notifikasi (webhook) dari DOKU.
 * $headers: array dengan kunci huruf kecil ('client-id', 'request-id',
 * 'request-timestamp', 'signature').
 */
function doku_verifikasi_notifikasi(string $rawBody, array $headers): bool {
    $c = doku_secret();
    foreach (['client-id', 'request-id', 'request-timestamp', 'signature'] as $k) {
        if (empty($headers[$k])) return false;
    }
    if (!hash_equals((string)$c['client_id'], (string)$headers['client-id'])) return false;
    $hitung = doku_signature(
        $headers['client-id'],
        $headers['request-id'],
        $headers['request-timestamp'],
        doku_webhook_target(),
        $rawBody,
        (string)$c['secret_key']
    );
    return hash_equals($hitung, (string)$headers['signature']);
}

/** Ambil semua header request sebagai array berkunci huruf kecil. */
function doku_semua_header(): array {
    $out = [];
    if (function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $k => $v) $out[strtolower($k)] = $v;
        return $out;
    }
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $out[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
        }
    }
    return $out;
}
