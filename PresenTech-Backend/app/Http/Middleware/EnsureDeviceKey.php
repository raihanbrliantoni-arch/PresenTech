<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang autentikasi semua endpoint yang dipakai perangkat fisik (ESP32).
 *
 * Sebelumnya setiap controller mencocokkan header X-Device-Key sendiri dan
 * route `POST /devices/{device}/heartbeat` bisa dipanggil dengan sesi Admin
 * saja, sehingga pemeriksaan key bisa terlewat. Middleware ini menaruh
 * perangkat hasil autentikasi di atribut request ('device') supaya controller
 * cukup memakainya tanpa mengulang logika autentikasi.
 */
class EnsureDeviceKey
{
    public const REQUEST_ATTRIBUTE = 'device';

    public function handle(Request $request, Closure $next): Response
    {
        self::authenticatePlease($request);

        $device = self::authenticate($request);

        if ($device === null) {
            return response()->json(['message' => 'Device key tidak valid.'], 401);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $device);

        return $next($request);
    }

    /**
     * Middleware ini berjalan sebelum auth:sanctum, sehingga pengguna dengan
     * Bearer token belum dikenal saat itu. Muat dulu bila ada token agar
     * controller tetap melihat sesi yang sama (mis. absensi manual Admin/Guru
     * lewat POST /attendance).
     */
    private static function authenticatePlease(Request $request): void
    {
        if ($request->bearerToken() === null || auth('sanctum')->user() !== null) {
            return;
        }

        $request->setUserResolver(static fn (?string $guard = null) => $guard === 'sanctum'
            ? auth('sanctum')->user()
            : $request->user($guard));
    }

    /**
     * Cocokkan header X-Device-Key dengan hash yang tersimpan.
     *
     * Pencarian memakai hash langsung (indeks unik), lalu hash_equals
     * dipakai sebagai lapisan kedua agar perbandingan tidak bocor waktu.
     */
    public static function authenticate(Request $request): ?Device
    {
        $key = trim((string) $request->header('X-Device-Key', ''));

        if ($key === '') {
            return null;
        }

        $device = Device::where('device_key', hash('sha256', $key))->first();

        if ($device === null || ! hash_equals((string) $device->device_key, hash('sha256', $key))) {
            return null;
        }

        return $device;
    }
}
