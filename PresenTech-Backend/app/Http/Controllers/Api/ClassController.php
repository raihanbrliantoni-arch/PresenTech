<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClassController extends Controller
{
    public function index(Request $request)
    {
        $query = SchoolClass::with('homeroomTeacher');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('major', 'like', "%{$search}%");
            });
        }

        // Urutan baku: tingkat X, XI, XII lebih dulu; di dalam tiap tingkat
        // baru RPL, DKV, TKJ, TRANSMISI; lalu nomor kelas. CASE dipakai agar
        // tetap jalan di MySQL dan SQLite.
        return response()->json(
            $query->withCount('students')
                ->orderByRaw("CASE WHEN name LIKE 'XII %' THEN 3 WHEN name LIKE 'XI %' THEN 2 WHEN name LIKE 'X %' THEN 1 ELSE 9 END")
                ->orderByRaw("CASE major WHEN 'RPL' THEN 1 WHEN 'DKV' THEN 2 WHEN 'TKJ' THEN 3 WHEN 'TRANSMISI' THEN 4 ELSE 9 END")
                ->orderBy('name')
                ->paginate(min(max((int) $request->input('per_page', 20), 1), 1000))
        );
    }

    public function show(SchoolClass $class)
    {
        return response()->json($class->load(['homeroomTeacher', 'students', 'schedules']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:school_classes,name'],
            'major' => ['nullable', 'string', 'max:100'],
            'homeroom_teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
            'student_count' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $class = SchoolClass::create($data);
        Audit::log($request, 'system', 'class.created', 'Kelas ditambahkan: '.$class->name, ['class_id' => $class->id]);

        return response()->json($class->load('homeroomTeacher'), 201);
    }

    public function update(Request $request, SchoolClass $class)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:50', Rule::unique('school_classes', 'name')->ignore($class->id)],
            'major' => ['nullable', 'string', 'max:100'],
            'homeroom_teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
            'student_count' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $class->update($data);
        Audit::log($request, 'system', 'class.updated', 'Kelas diubah: '.$class->name, ['class_id' => $class->id]);

        return response()->json($class->fresh()->load('homeroomTeacher'));
    }

    public function destroy(Request $request, SchoolClass $class)
    {
        // Blokir penghapusan kelas yang masih beranggotakan siswa: FK akan
        // menghentikan delete yang menyentuh riwayat absensi, dan cascade
        // implisit ke siswa/kartu RFID tidak boleh terjadi diam-diam.
        if ($class->students()->exists()) {
            return response()->json([
                'message' => 'Kelas tidak bisa dihapus karena masih berisi siswa. Pindahkan siswa ke kelas lain terlebih dahulu.',
            ], 409);
        }

        Audit::log($request, 'system', 'class.deleted', 'Kelas dihapus: '.$class->name, ['class_id' => $class->id]);
        $class->delete();

        return response()->json(['message' => 'Kelas berhasil dihapus.']);
    }
}
