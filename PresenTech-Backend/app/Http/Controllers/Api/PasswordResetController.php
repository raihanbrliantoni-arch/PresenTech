<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\PasswordResetRequest;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    /**
     * Ajukan reset password dari halaman Lupa Password. Permintaan TIDAK
     * mengubah apa pun secara langsung: Admin wajib menyetujui lebih dulu.
     * Tanpa langkah ini siapa pun yang tahu NIS/NIP bisa membajak akun.
     */
    public function request(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:100'],
            'role' => ['nullable', 'in:siswa,guru'],
        ]);

        $identifier = trim($data['identifier']);
        $requestedRole = $data['role'] ?? null;

        // Prioritas pencarian mengikuti peran yang dipilih agar NIS yang
        // sama persis dengan NIP guru (data kembar) tetap diarahkan benar.
        $student = $requestedRole === 'guru' ? null : Student::where('nis', $identifier)->first();
        $teacher = $requestedRole === 'siswa' ? null : Teacher::where('nip', $identifier)->first();

        // Siswa wajib memakai NIS, Guru wajib memakai NIP — identitas
        // satu peran tidak boleh dipakai untuk peran lain.
        if ($requestedRole === 'siswa' && ! $student) {
            throw ValidationException::withMessages([
                'identifier' => [$teacher ? 'Ini NIP guru, bukan NIS siswa. Pilih Guru dan masukkan NIP.' : 'NIS tidak ditemukan. Periksa kembali NIS siswa.'],
            ]);
        }

        if ($requestedRole === 'guru' && ! $teacher) {
            throw ValidationException::withMessages([
                'identifier' => [$student ? 'Ini NIS siswa, bukan NIP guru. Pilih Siswa dan masukkan NIS.' : 'NIP tidak ditemukan. Periksa kembali NIP guru.'],
            ]);
        }

        if (! $student && ! $teacher) {
            throw ValidationException::withMessages([
                'identifier' => [
                    $requestedRole === 'siswa' ? 'NIS tidak ditemukan. Periksa kembali NIS siswa.' :
                    ($requestedRole === 'guru' ? 'NIP tidak ditemukan. Periksa kembali NIP guru.' : 'NIS/NIP tidak ditemukan.'),
                ],
            ]);
        }

        $person = $student ?: $teacher;
        $role = $student ? 'siswa' : 'guru';
        $user = $person->user;

        if (! $user) {
            throw ValidationException::withMessages([
                'identifier' => ['Akun login belum dibuat. Hubungi Admin untuk membuatkan akun terlebih dahulu.'],
            ]);
        }

        // Login hanya diizinkan untuk data berstatus Aktif — permintaan
        // reset untuk data Nonaktif tidak akan berguna.
        if (($person->status ?? 'Nonaktif') !== 'Aktif') {
            throw ValidationException::withMessages([
                'identifier' => ['Data berstatus '.($person->status ?? 'Nonaktif').'. Hubungi Admin untuk mengaktifkan sebelum reset password.'],
            ]);
        }

        // Permintaan PENDING yang masih terbuka dipakai ulang supaya riwayat
        // di panel Admin tidak menumpuk baris kembar untuk orang yang sama.
        // Token klaim selalu dibuat baru; token lama otomatis tidak berlaku.
        $reset = PasswordResetRequest::where('identifier', $identifier)
            ->where('status', 'PENDING')
            ->latest()
            ->first() ?? new PasswordResetRequest;

        $claimToken = Str::random(64);

        $reset->fill([
            'user_id' => $user->id,
            'identifier' => $identifier,
            'role' => $role,
            'status' => 'PENDING',
            'claim_token' => hash('sha256', $claimToken),
            'review_note' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'expires_at' => now()->addHours(24),
        ])->save();

        Audit::log($request, 'system', 'auth.password_reset_requested', 'Pengguna mengajukan reset password', [
            'user_id' => $user->id,
            'role' => $role,
            'identifier' => $identifier,
        ]);

        User::where('role', 'admin')->get()->each(function ($admin) use ($user, $identifier, $role) {
            Notification::create([
                'recipient_user_id' => $admin->id,
                'sender_user_id' => $user->id,
                'recipient_role' => 'admin',
                'type' => 'system',
                'title' => 'Permintaan reset password',
                'message' => strtoupper($role).' dengan '.$identifier.' mengajukan reset password. Setujui lewat menu Reset Password bila identitasnya sudah dipastikan.',
                'icon' => 'key',
                'expires_at' => now()->addHours(24),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Permintaan reset password dikirim. Password baru bisa dipakai setelah Admin menyetujui.',
            'request' => $this->publicResetShape($reset),
            'token' => $claimToken,
        ], 201);
    }

    /**
     * Bentuk aman untuk pemohon: hanya id, identifier, status, dan masa berlaku.
     * Tidak membocorkan user_id, role, reviewer, atau catatan review.
     */
    private function publicResetShape(PasswordResetRequest $reset): array
    {
        $status = $reset->status;
        if ($status === 'PENDING' && $reset->expires_at !== null && $reset->expires_at->isPast()) {
            $status = 'EXPIRED';
        }

        return [
            'id' => $reset->id,
            'identifier' => $reset->identifier,
            'status' => $status,
            'expires_at' => $reset->expires_at?->toIso8601String(),
        ];
    }

    public function status(Request $request)
    {
        $data = $request->validate([
            'request_id' => ['required', 'integer'],
            'token' => ['required', 'string', 'max:128'],
        ]);

        // Status hanya bisa dicek dengan id + token klaim rahasia. Id saja
        // bersifat berurutan sehingga bisa dipakai menebak identitas orang lain.
        $reset = PasswordResetRequest::query()
            ->whereKey($data['request_id'])
            ->where('claim_token', hash('sha256', $data['token']))
            ->first();

        if (! $reset) {
            return response()->json([
                'success' => true,
                'request' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'request' => $this->publicResetShape($reset),
        ]);
    }

    /**
     * Persetujuan Admin: password sementara menjadi NIS/NIP dan pengguna
     * wajib menggantinya saat login pertama.
     */
    public function approve(Request $request, PasswordResetRequest $reset)
    {
        abort_unless($request->user()?->role === 'admin', 403);

        if ($reset->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'Permintaan sudah diproses.',
            ], 422);
        }

        if ($reset->expires_at !== null && $reset->expires_at->isPast()) {
            $reset->update([
                'status' => 'REJECTED',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => 'Kedaluwarsa sebelum disetujui Admin.',
                'claim_token' => null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Permintaan sudah kedaluwarsa. Minta pengguna mengajukan ulang.',
            ], 422);
        }

        $user = $reset->user;
        abort_if($user === null, 404);

        $password = $reset->identifier;

        $user->forceFill([
            'password' => $password,
            'must_change_password' => true,
        ])->save();
        $user->tokens()->delete();

        $reset->update([
            'status' => 'COMPLETED',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => 'Disetujui Admin; password sementara direset menjadi '.$password.' dan wajib diganti saat login.',
            'claim_token' => null,
        ]);

        Audit::log($request, 'system', 'auth.password_reset_approved', 'Admin menyetujui reset password', [
            'user_id' => $user->id,
            'role' => $reset->role,
            'identifier' => $reset->identifier,
        ]);

        Notification::create([
            'recipient_user_id' => $reset->user_id,
            'sender_user_id' => $request->user()->id,
            'recipient_role' => $reset->role,
            'type' => 'system',
            'title' => 'Reset password disetujui',
            'message' => 'Permintaan reset password kamu telah disetujui Admin. Password sementara sama dengan '.$password.'; ganti segera setelah login.',
            'icon' => 'key',
            'expires_at' => now()->addHours(24),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Permintaan disetujui. Password sementara pengguna sama dengan NIS/NIP dan wajib diganti saat login.',
            'request' => $reset->fresh(),
            'initial_password' => $password,
        ]);
    }

    public function reject(Request $request, PasswordResetRequest $reset)
    {
        abort_unless($request->user()?->role === 'admin', 403);

        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($reset->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'Permintaan sudah diproses.',
            ], 422);
        }

        $reset->update([
            'status' => 'REJECTED',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? 'Permintaan tidak disetujui Admin.',
        ]);

        Notification::create([
            'recipient_user_id' => $reset->user_id,
            'sender_user_id' => $request->user()->id,
            'recipient_role' => $reset->role,
            'type' => 'system',
            'title' => 'Reset password ditolak',
            'message' => $reset->review_note ?: 'Permintaan reset password kamu ditolak Admin.',
            'icon' => 'key',
            'expires_at' => now()->addHours(24),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Permintaan reset password ditolak.',
            'request' => $reset->fresh(),
        ]);
    }

    /**
     * Reset password langsung oleh Admin (untuk pengguna yang terkunci).
     * Tanpa password pilihan Admin, password akun disamakan dengan
     * NIS/NIP (username) agar bisa langsung dipakai login.
     */
    public function adminSetPassword(Request $request, User $user)
    {
        $data = $request->validate([
            'password' => ['nullable', 'string', Password::min(8)->letters()->numbers()],
        ]);

        $explicitPassword = $data['password'] ?? null;
        $password = $explicitPassword ?? $user->username;

        $user->forceFill([
            'password' => $password,
            // Baik password pilihan Admin maupun NIS/NIP sama-sama bersifat
            // sementara dan wajib diganti saat login pertama.
            'must_change_password' => true,
        ])->save();
        $user->tokens()->delete();

        Audit::log($request, 'system', 'auth.password_reset_by_admin', 'Admin mereset password pengguna', [
            'user_id' => $user->id,
            'role' => $user->role,
        ]);

        if ($user->id !== $request->user()->id) {
            Notification::create([
                'recipient_user_id' => $user->id,
                'sender_user_id' => $request->user()->id,
                'recipient_role' => $user->role,
                'type' => 'system',
                'title' => 'Password direset Admin',
                'message' => $explicitPassword
                    ? 'Password kamu telah direset oleh Admin. Gunakan password dari Admin, lalu segera ganti.'
                    : 'Password sementara kamu sekarang sama dengan NIS/NIP akunmu. Segera ganti setelah login.',
                'icon' => 'key',
                'expires_at' => now()->addHours(24),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Password sementara berhasil direset dan wajib diganti saat login.',
            'initial_password' => $password,
            'must_change_password' => true,
        ]);
    }

    public function adminIndex(Request $request)
    {
        abort_unless($request->user()?->role === 'admin', 403);

        $query = PasswordResetRequest::with(['user', 'reviewer'])->latest();
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('identifier', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        $requests = $query->paginate(min(max((int) $request->input('per_page', 20), 1), 100));

        return response()->json([
            'success' => true,
            'requests' => $requests,
        ]);
    }
}
