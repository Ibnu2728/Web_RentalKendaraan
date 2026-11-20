<?php
// ============================================================
//  CONTOH file rahasia DOKU.
//  Salin file ini menjadi  api/doku_secret.php  lalu isi nilainya.
//  File doku_secret.php TIDAK ikut ter-commit (sudah di .gitignore) —
//  Secret Key tidak boleh muncul di GitHub, HTML, atau JavaScript.
//
//  Client ID & Secret Key didapat dari dashboard DOKU:
//    Sandbox    : https://sandbox.doku.com  (untuk pengujian, uang mainan)
//    Production : https://dashboard.doku.com (uang sungguhan)
// ============================================================
return [
    // Dari dashboard DOKU (menu Integration / Pengaturan API).
    'client_id' => 'BRN-xxxx-xxxxxxxxxxxxxxxx',
    'secret_key' => 'SK-xxxxxxxxxxxxxxxxxxxx',

    // 'sandbox' selama pengujian, ganti 'production' saat go-live.
    'mode' => 'sandbox',

    // Alamat publik situs ini TANPA garis miring di akhir.
    // Dipakai sebagai alamat kembali (callback_url) setelah user membayar
    // di halaman DOKU. Untuk pengujian lokal, isi dengan alamat ngrok
    // (mis. https://abcd-1234.ngrok-free.app/Rental_Kendaraan) karena
    // server DOKU tidak bisa menjangkau localhost.
    'base_url' => 'http://localhost/Rental_Kendaraan',

    // Path endpoint webhook PERSIS seperti yang didaftarkan di dashboard
    // DOKU (menu Notification URL). Nilai ini ikut dipakai saat
    // memverifikasi signature, jadi harus sama persis dengan URL yang
    // dipanggil DOKU, contoh: '/Rental_Kendaraan/api/doku_webhook.php'.
    'webhook_target' => '/Rental_Kendaraan/api/doku_webhook.php',
];
