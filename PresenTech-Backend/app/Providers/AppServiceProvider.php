<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rate limit endpoint auth publik (login, register, lupa password)
        // berdasarkan identifier + IP agar tidak mudah di-brute-force.
        RateLimiter::for('auth', function (Request $request) {
            // Kunci per identifier + IP. Bila hanya identifier, satu penyerang
            // bisa mengunci akun orang lain cukup dengan 10 percobaan gagal.
            $identifier = strtolower((string) $request->input('identifier', ''));

            return [
                Limit::perMinute(10)->by($identifier.'|'.$request->ip()),
                Limit::perMinute(120)->by($request->ip()),
            ];
        });

        // Rate limit endpoint perangkat (heartbeat, tap kartu, sinkronisasi).
        // Dikunci per device key agar beberapa perangkat di balik satu NAT
        // tidak saling menghabiskan kuota; tanpa key, fallback ke IP.
        RateLimiter::for('device', function (Request $request) {
            $deviceKey = $request->header('X-Device-Key');

            if (is_string($deviceKey) && $deviceKey !== '') {
                return Limit::perMinute(60)->by('device:'.hash('sha256', $deviceKey));
            }

            return Limit::perMinute(120)->by('ip:'.$request->ip());
        });
    }
}
