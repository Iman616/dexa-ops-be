<?php
// config/cors.php — Laravel

return [

    /*
     * Paths that CORS headers are applied to.
     */
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://localhost:3000',
        // tambahkan production URL di sini
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    /*
     * ✅ FIX UTAMA: Cache preflight selama 24 jam (86400 detik)
     *
     * Sebelumnya: 0 (default) → browser kirim OPTIONS tiap request → +700ms-1.2s delay
     * Sesudah: 86400 → browser cache preflight → tidak perlu OPTIONS lagi
     *
     * Browser support: Chrome max 7200s, Firefox max 86400s, Safari max 600s
     * Pakai 7200 untuk kompatibilitas maksimal.
     */
    'max_age' => 7200,

    'supports_credentials' => true,

];
