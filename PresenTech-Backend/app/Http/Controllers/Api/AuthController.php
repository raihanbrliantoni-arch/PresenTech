<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function createStudentAccount(Request $request, Student $student)
    {
        return $this->createAccountFor($request, $student, 'siswa', $student->nis);
    }

    public function createTeacherAccount(Request $request, Teacher $teacher)
    {
        return $this->createAccountFor($request, $teacher, 'guru', $teacher->nip);
    }

    /**
     * Akun hanya dibuat Admin dari data Siswa/Guru yang sudah ada.
     * Username sekaligus password awal adalah NIS/NIP; karena NIS/NIP
     * mudah ditebak, pengguna wajib menggantinya saat login pertama.
     */
    private function createAccountFor(Request $request, Student|Teacher $person, string $role, ?string $identifier)
    {
        $identifier = trim((string) $identifier);

        if ($identifier === '') {
            throw ValidationException::withMessages([
                'identifier' => ['NIS/NIP wajib diisi sebelum membuat akun.'],
            ]);
        }

        if ($person->user_id) {
            throw ValidationException::withMessages([
                'identifier' => ['Data ini sudah memiliki akun.'],
            ]);
        }

        // Jangan buat akun yang pasti tidak bisa dipakai: login hanya
        // diizinkan untuk data personil berstatus Aktif.
        if (($person->status ?? 'Nonaktif') !== 'Aktif') {
            throw ValidationException::withMessages([
                'identifier' => ['Akun hanya bisa dibuat untuk data berstatus Aktif. Ubah status terlebih dahulu.'],
            ]);
        }

        if (User::where('username', $identifier)->exists()) {
            throw ValidationException::withMessages([
                'identifier' => ['NIS/NIP tersebut sudah digunakan sebagai akun.'],
            ]);
        }

        try {
            $user = new User([
                'name' => $person->name,
                'username' => $identifier,
                'phone' => $person->phone ?? null,
                'avatar' => null,
                'password' => $identifier,
            ]);
            $user->role = $role;
            $user->forceFill(['must_change_password' => true])->save();

            $person->user_id = $user->id;
            $person->save();
        } catch (QueryException $e) {
            throw ValidationException::withMessages([
                'identifier' => ['NIS/NIP tersebut sudah digunakan sebagai akun.'],
            ]);
        }

        Audit::log($request, 'system', 'account.created', 'Akun login dibuat untuk '.$person->name, [
            'user_id' => $user->id,
            'role' => $role,
            'identifier' => $identifier,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Akun berhasil dibuat. Password sementara sama dengan NIS/NIP dan wajib diganti saat login pertama.',
            'user' => $user,
            'initial_password' => $identifier,
        ], 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
        ]);

        $identifier = trim($validated['identifier']);

        $user = User::query()
            ->where('username', $identifier)
            ->first();

        if (! $user) {
            $teacher = Teacher::query()
                ->where('nip', $identifier)
                ->first();

            if ($teacher) {
                $user = $teacher->user;
            } else {
                $student = Student::query()
                    ->where('nis', $identifier)
                    ->first();

                if ($student) {
                    $user = $student->user;
                }
            }
        }

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            Audit::log($request, 'system', 'auth.login_failed', 'Percobaan login gagal', [
                'identifier' => $identifier,
                'reason' => $user ? 'password_salah' : 'akun_tidak_ditemukan',
            ]);

            throw ValidationException::withMessages([
                'identifier' => ['NIS/NIP atau password salah.'],
            ]);
        }

        // Hash lama (cost 12) otomatis dimigrasikan ke cost terbaru saat
        // login berhasil, sehingga login berikutnya lebih cepat tanpa
        // memaksa pengguna mengganti password.
        if (Hash::needsRehash($user->password)) {
            $user->update(['password' => $validated['password']]);
        }

        // Akun mengikuti status data personil: hanya status Aktif yang boleh
        // memiliki sesi. Token lama dicabut agar akun nonaktif langsung keluar.
        if (in_array($user->role, ['siswa', 'guru'], true)) {
            $personStatus = $user->role === 'siswa'
                ? $user->student?->status
                : $user->teacher?->status;

            if (($personStatus ?? 'Nonaktif') !== 'Aktif') {
                $user->tokens()->delete();

                Audit::log($request, 'system', 'auth.login_blocked', 'Login ditolak karena akun nonaktif', [
                    'user_id' => $user->id,
                    'identifier' => $identifier,
                ]);

                throw ValidationException::withMessages([
                    'identifier' => ['Akun tidak aktif. Hubungi Admin untuk mengaktifkan kembali.'],
                ]);
            }
        }

        // Multi-device: JANGAN hapus token perangkat lain di sini —
        // setiap login membuat token baru; siklus hidup diatur expiry 7 hari
        // dan pencabutan manual via change-password/logout.
        $token = $user->createToken('presentech-web')->plainTextToken;

        Audit::log($request, 'system', 'auth.login', 'Login berhasil', [
            'user_id' => $user->id,
            'role' => $user->role,
            'identifier' => $identifier,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil.',
            'token' => $token,
            'user' => $user->load(['teacher', 'student.schoolClass']),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'success' => true,
            'user' => $request->user()->load(['teacher', 'student.schoolClass']),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout berhasil.',
        ]);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'avatar' => ['nullable', 'string', 'max:262144'],
        ]);
        $user = $request->user();
        $user->update($data);
        if ($user->role === 'siswa' && $user->student) {
            $user->student->update(array_intersect_key($data, array_flip(['name', 'phone'])));
        }
        if ($user->role === 'guru' && $user->teacher) {
            $user->teacher->update(array_intersect_key($data, array_flip(['name', 'phone'])));
        }

        return response()->json(['success' => true, 'user' => $user->fresh()->load(['teacher', 'student.schoolClass'])]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers(), 'confirmed'],
        ]);
        if (! Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => ['Password saat ini salah.']]);
        }
        $request->user()->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
        ])->save();

        Audit::log($request, 'system', 'auth.password_changed', 'Pengguna mengganti password', [
            'user_id' => $request->user()->id,
        ]);

        // Setelah ganti password, cabut semua token lain; hanya sesi
        // aktif ini yang tetap valid.
        $current = $request->user()->currentAccessToken();
        if ($current) {
            $request->user()->tokens()->whereKeyNot($current->id)->delete();
        }

        return response()->json(['success' => true, 'message' => 'Password berhasil diperbarui.']);
    }
}
