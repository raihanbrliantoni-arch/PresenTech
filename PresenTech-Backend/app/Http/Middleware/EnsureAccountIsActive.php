<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * Akun Siswa/Guru hanya boleh memakai API selama data personilnya
     * berstatus Aktif. Perubahan status dari Admin langsung mencabut sesi.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && in_array($user->role, ['siswa', 'guru'], true)) {
            $status = $user->role === 'siswa'
                ? $user->student?->status
                : $user->teacher?->status;

            if (($status ?? 'Nonaktif') !== 'Aktif') {
                $user->currentAccessToken()?->delete();

                return response()->json([
                    'message' => 'Akun tidak aktif. Hubungi Admin untuk mengaktifkan kembali.',
                ], 403);
            }
        }

        return $next($request);
    }
}
