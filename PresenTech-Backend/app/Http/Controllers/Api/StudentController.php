<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $query = Student::with(['schoolClass', 'rfidCards']);

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('nis', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->integer('class_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        // Nomor absen lebih dulu (yang kosong di belakang), lalu nama.
        return response()->json(
            $query->orderByRaw('CASE WHEN no_absen IS NULL THEN 1 ELSE 0 END')
                ->orderBy('no_absen')
                ->orderBy('name')
                ->paginate(min(max((int) $request->input('per_page', 20), 1), 1000))
        );
    }

    public function show(Student $student)
    {
        return response()->json($student->load(['schoolClass', 'rfidCards', 'attendances']));
    }

    /**
     * Isi nomor absen otomatis per kelas (urut nama A-Z). Default hanya
     * mengisi yang masih kosong; overwrite=true menomori ulang seluruh kelas.
     */
    public function autoNumber(Request $request)
    {
        $data = $request->validate([
            'overwrite' => ['nullable', 'boolean'],
            'class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
        ]);

        $overwrite = (bool) ($data['overwrite'] ?? false);
        $updated = 0;

        SchoolClass::query()
            ->when(! empty($data['class_id']), fn ($query) => $query->whereKey($data['class_id']))
            ->orderBy('name')
            ->get()
            ->each(function (SchoolClass $class) use ($overwrite, &$updated) {
                $students = Student::query()
                    ->where('class_id', $class->id)
                    ->where('status', 'Aktif')
                    ->orderBy('name')
                    ->get();

                $next = $overwrite
                    ? 0
                    : (int) Student::query()->where('class_id', $class->id)->max('no_absen');

                foreach ($students as $student) {
                    if (! $overwrite && $student->no_absen !== null) {
                        continue;
                    }

                    $student->update(['no_absen' => ++$next]);
                    $updated++;
                }
            });

        Audit::log($request, 'system', 'student.auto_number', 'Nomor absen diatur otomatis', [
            'updated' => $updated,
            'overwrite' => $overwrite,
        ]);

        return response()->json([
            'success' => true,
            'updated' => $updated,
            'message' => $updated > 0
                ? "Nomor absen diperbarui untuk {$updated} siswa."
                : 'Semua siswa sudah punya nomor absen.',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => [
                'nullable', 'integer', 'exists:users,id',
                fn (string $attribute, mixed $value, Closure $fail) => $this->ensureLinkableUser($value, null, $fail),
            ],
            'class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'nis' => ['required', 'string', 'max:30', 'unique:students,nis'],
            'name' => ['required', 'string', 'max:255'],
            'no_absen' => ['nullable', 'integer', 'min:1', 'max:999'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', Rule::in(['Aktif', 'Nonaktif', 'Lulus', 'Pindah'])],
        ]);

        $student = Student::create($data);
        $this->recalcClassCount($student->class_id);
        Audit::log($request, 'system', 'student.created', 'Siswa ditambahkan: '.$student->name, ['student_id' => $student->id]);

        return response()->json($student->load('schoolClass'), 201);
    }

    public function update(Request $request, Student $student)
    {
        $data = $request->validate([
            'user_id' => [
                'nullable', 'integer', 'exists:users,id',
                fn (string $attribute, mixed $value, Closure $fail) => $this->ensureLinkableUser($value, $student->id, $fail),
            ],
            'class_id' => ['sometimes', 'nullable', 'integer', 'exists:school_classes,id'],
            'nis' => ['sometimes', 'string', 'max:30', Rule::unique('students', 'nis')->ignore($student->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'no_absen' => ['nullable', 'integer', 'min:1', 'max:999'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['sometimes', Rule::in(['Aktif', 'Nonaktif', 'Lulus', 'Pindah'])],
        ]);

        $oldClassId = $student->class_id;
        $student->update($data);
        $this->recalcClassCount($oldClassId);
        $this->recalcClassCount($student->fresh()->class_id);
        if ($student->user_id && (array_key_exists('name', $data) || array_key_exists('phone', $data))) {
            $student->user?->update(array_filter([
                'name' => $data['name'] ?? null,
                'phone' => $data['phone'] ?? null,
            ], fn ($v) => $v !== null));
        }
        if ($student->user_id && (array_key_exists('class_id', $data) || array_key_exists('status', $data) || array_key_exists('name', $data))) {
            $student->load('schoolClass');
            Notification::create([
                'recipient_user_id' => $student->user_id,
                'recipient_role' => 'siswa',
                'type' => 'system',
                'title' => 'Data kelas diperbarui',
                'message' => 'Kelas kamu sekarang: '.($student->schoolClass?->name ?? 'Belum ditentukan').'.',
                'expires_at' => now()->addHours(24),
            ]);
        }
        Audit::log($request, 'system', 'student.updated', 'Data siswa diubah: '.$student->name, ['student_id' => $student->id]);

        return response()->json($student->fresh()->load('schoolClass'));
    }

    public function destroy(Request $request, Student $student)
    {
        // FK attendances/attendance_corrections ke students adalah restrict:
        // cek dulu agar client mendapat 409 yang jelas, bukan 500.
        if ($student->attendances()->exists() || $student->corrections()->exists()) {
            return response()->json([
                'message' => 'Siswa tidak bisa dihapus karena masih memiliki riwayat absensi/koreksi. Ubah status siswa menjadi Nonaktif, atau hapus riwayatnya terlebih dahulu.',
            ], 409);
        }

        $classId = $student->class_id;
        Audit::log($request, 'system', 'student.deleted', 'Siswa dihapus: '.$student->name, ['student_id' => $student->id]);
        $student->delete();
        $this->recalcClassCount($classId);

        return response()->json(['message' => 'Siswa berhasil dihapus.']);
    }

    /**
     * Akun yang ditautkan harus berperan siswa dan belum dipakai siswa lain.
     * Tanpa ini satu akun bisa tertaut ke dua data siswa tanpa peringatan.
     */
    private function ensureLinkableUser(mixed $userId, ?int $ignoreStudentId, Closure $fail): void
    {
        if ($userId === null || $userId === '') {
            return;
        }

        $user = User::find($userId);

        if ($user === null) {
            return; // sudah ditangani aturan exists:users,id
        }

        if ($user->role !== 'siswa') {
            $fail('Akun yang ditautkan harus memiliki peran siswa.');

            return;
        }

        $query = Student::where('user_id', $userId);
        if ($ignoreStudentId !== null) {
            $query->whereKeyNot($ignoreStudentId);
        }

        if ($query->exists()) {
            $fail('Akun ini sudah tertaut ke data siswa lain.');
        }
    }

    /**
     * Jaga agar kolom school_classes.student_count selalu konsisten
     * dengan jumlah siswa aktual di kelas tersebut.
     */
    private function recalcClassCount(?int $classId): void
    {
        if ($classId === null) {
            return;
        }

        SchoolClass::where('id', $classId)->update([
            'student_count' => Student::where('class_id', $classId)->count(),
        ]);
    }
}
