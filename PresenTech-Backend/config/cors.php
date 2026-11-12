<?php

// Origin frontend yang boleh memanggil API. Default mendukung server dev Vite;
// untuk produksi tambahkan origin publik (mis. https://presensisekolah.sch.id)
// via FRONTEND_URL atau CORS_ALLOWED_ORIGINS di .env, dipisah koma.
$extraOrigins = array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))));

if (filled(env('FRONTEND_URL'))) {
    $extraOrigins[] = rtrim((string) env('FRONTEND_URL'), '/');
}

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_unique(array_merge(
        ['http://localhost:5173', 'http://127.0.0.1:5173'],
        $extraOrigins
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        'X-Device-Key',
    ],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
