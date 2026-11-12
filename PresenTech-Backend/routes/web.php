<?php

use Illuminate\Support\Facades\Route;

// Aplikasi React (hasil build) disajikan dari satu origin dengan API,
// sehingga akses publik cukup satu URL (tunnel/hosting).
Route::get('/app/{any?}', function () {
    $index = public_path('app/index.html');

    abort_unless(is_file($index), 404, 'Build frontend belum tersedia.');

    // index.html tidak boleh di-cache agar setiap build baru (aset ber-hash
    // baru) langsung dipakai setelah halaman dimuat ulang.
    return response()->file($index, [
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Pragma' => 'no-cache',
    ]);
})->where('any', '.*');

Route::get('/', function () {
    return view('welcome');
});
