<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun dengan password sementara dari Admin hanya boleh mengakses
 * profil sendiri, ganti password, dan logout. Endpoint lain ditolak
 * sampai password diganti agar password sementara tidak dipakai permanen.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->must_change_password) {
            $allowed = $request->is('api/me', 'api/change-password', 'api/logout');

            if (! $allowed) {
                return response()->json([
                    'success' => false,
                    'code' => 'password_change_required',
                    'message' => 'Password sementara wajib diganti sebelum memakai fitur lain.',
                ], 428);
            }
        }

        return $next($request);
    }
}
