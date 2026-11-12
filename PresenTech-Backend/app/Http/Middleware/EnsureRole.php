<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Pastikan user yang terautentikasi memiliki salah satu role yang diizinkan.
     * Contoh pemakaian di route: ->middleware('role:admin') atau ('role:admin,guru').
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless($user !== null, 401, 'Autentikasi diperlukan.');
        abort_unless(in_array($user->role, $roles, true), 403, 'Akses ditolak untuk role kamu.');

        return $next($request);
    }
}
