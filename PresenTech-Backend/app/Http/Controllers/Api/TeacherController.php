<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\Notification;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherController extends Controller
{
    public function index(Request $request)
    {
        $query = Teacher::with('user');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('nip', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->orderBy('name')->paginate(min(max((int) $request->input('per_page', 20), 1), 1000)));
    }

    public function show(Teacher $teacher)
    {
        return response()->json($teacher->load(['user', 'classes', 'schedules']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => [
                'nullable', 'integer', 'exists:users,id',
                fn (string $attribute, mixed $value, Closure $fail) => $this->ensureLinkableUser($value, null, $fail),
            ],
            'nip' => ['required', 'string', 'max:30', 'unique:teachers,nip'],
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', Rule::in(['Aktif', 'Nonaktif'])],
        ]);

        $teacher = Teacher::create($data);
        Audit::log($request, 'system', 'teacher.created', 'Guru ditambahkan: '.$teacher->name, ['teacher_id' => $teacher->id]);

        return response()->json($teacher->load('user'), 201);
    }

    public function update(Request $request, Teacher $teacher)
    {
        $data = $request->validate([
            'user_id' => [
                'nullable', 'integer', 'exists:users,id',
                fn (string $attribute, mixed $value, Closure $fail) => $this->ensureLinkableUser($value, $teacher->id, $fail),
            ],
            'nip' => ['sometimes', 'string', 'max:30', Rule::unique('teachers', 'nip')->ignore($teacher->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['sometimes', Rule::in(['Aktif', 'Nonaktif'])],
        ]);

        $teacher->update($data);
        if ($teacher->user_id && (array_key_exists('name', $data) || array_key_exists('phone', $data) || array_key_exists('subject', $data))) {
            $teacher->user?->update(array_filter([
                'name' => $data['name'] ?? null,
                'phone' => $data['phone'] ?? null,
            ], fn ($v) => $v !== null));

            Notification::create([
                'recipient_user_id' => $teacher->user_id,
                'recipient_role' => 'guru',
                'type' => 'system',
                'title' => 'Data profil diperbarui',
                'message' => 'Data akun guru kamu telah diperbarui Admin.',
                'expires_at' => now()->addHours(24),
            ]);
        }
        Audit::log($request, 'system', 'teacher.updated', 'Data guru diubah: '.$teacher->name, ['teacher_id' => $teacher->id]);

        return response()->json($teacher->fresh()->load('user'));
    }

    /**
     * Akun yang ditautkan harus berperan guru/admin dan belum dipakai data
     * guru lain. Kolom teachers.user_id unik, jadi cek ini juga mencegah 500.
     */
    private function ensureLinkableUser(mixed $userId, ?int $ignoreTeacherId, Closure $fail): void
    {
        if ($userId === null || $userId === '') {
            return;
        }

        $user = User::find($userId);

        if ($user === null) {
            return; // sudah ditangani aturan exists:users,id
        }

        if (! in_array($user->role, ['guru', 'admin'], true)) {
            $fail('Akun yang ditautkan harus memiliki peran guru atau admin.');

            return;
        }

        $query = Teacher::where('user_id', $userId);
        if ($ignoreTeacherId !== null) {
            $query->whereKeyNot($ignoreTeacherId);
        }

        if ($query->exists()) {
            $fail('Akun ini sudah tertaut ke data guru lain.');
        }
    }

    public function destroy(Request $request, Teacher $teacher)
    {
        // Jadwal (restrict FK) dan pengajuan koreksi yang dibuat guru
        // (requested_by restrict FK) sama-sama memblokir delete: beri 409
        // yang jelas, bukan 500 di produksi MySQL.
        if ($teacher->schedules()->exists()
            || ($teacher->user_id && AttendanceCorrection::where('requested_by', $teacher->user_id)->exists())) {
            return response()->json([
                'message' => 'Guru tidak bisa dihapus karena masih memiliki jadwal pelajaran atau pengajuan koreksi. Alihkan/hapus jadwal dan koreksi guru terlebih dahulu.',
            ], 409);
        }

        Audit::log($request, 'system', 'teacher.deleted', 'Guru dihapus: '.$teacher->name, ['teacher_id' => $teacher->id]);
        $teacher->delete();

        return response()->json(['message' => 'Guru berhasil dihapus.']);
    }

    /**
     * Angkat guru menjadi admin. Guru tetap tercatat sebagai data guru
     * (NIP, jadwal, wali kelas); hanya rolenya di users yang berubah.
     */
    public function promote(Request $request, Teacher $teacher)
    {
        $user = $teacher->user;

        if ($user === null) {
            return response()->json([
                'message' => 'Guru ini belum memiliki akun. Akun dibuat saat guru registrasi dengan NIP-nya.',
            ], 422);
        }

        if ($user->role !== 'admin') {
            // Role bukan mass-assignable — diisi eksplisit seperti di seeder.
            $user->role = 'admin';
            $user->save();

            Notification::create([
                'recipient_user_id' => $teacher->user_id,
                'recipient_role' => 'admin',
                'type' => 'system',
                'title' => 'Akses Admin diberikan',
                'message' => 'Kamu sekarang memiliki akses Admin PresenTech.',
                'expires_at' => now()->addHours(24),
            ]);

            Audit::log($request, 'system', 'teacher.promoted', 'Guru diangkat menjadi Admin: '.$teacher->name, ['teacher_id' => $teacher->id, 'user_id' => $user->id]);
        }

        return response()->json([
            'message' => $teacher->name.' sekarang memiliki akses Admin.',
            'teacher' => $teacher->load('user'),
        ]);
    }

    /**
     * Cabut akses admin dari guru. Dilindungi dua pengaman:
     * tidak bisa memodifikasi akun sendiri, dan tidak boleh
     * mematikan admin terakhir yang tersisa.
     */
    public function demote(Request $request, Teacher $teacher)
    {
        $user = $teacher->user;

        if ($user === null) {
            return response()->json([
                'message' => 'Guru ini belum memiliki akun.',
            ], 422);
        }

        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'Tidak bisa mencabut akses Admin dari akunmu sendiri. Gunakan akun Admin lain.',
            ], 403);
        }

        if ($user->role === 'admin' && User::where('role', 'admin')->whereKeyNot($user->id)->doesntExist()) {
            return response()->json([
                'message' => 'Minimal harus ada satu Admin aktif. Angkat Admin lain terlebih dahulu.',
            ], 422);
        }

        if ($user->role !== 'guru') {
            $user->role = 'guru';
            $user->save();

            Notification::create([
                'recipient_user_id' => $teacher->user_id,
                'recipient_role' => 'guru',
                'type' => 'system',
                'title' => 'Akses Admin dicabut',
                'message' => 'Akses Admin akun guru kamu telah dicabut Admin.',
                'expires_at' => now()->addHours(24),
            ]);

            Audit::log($request, 'system', 'teacher.demoted', 'Akses Admin dicabut dari: '.$teacher->name, ['teacher_id' => $teacher->id, 'user_id' => $user->id]);
        }

        return response()->json([
            'message' => 'Akses Admin '.$teacher->name.' berhasil dicabut.',
            'teacher' => $teacher->load('user'),
        ]);
    }
}
