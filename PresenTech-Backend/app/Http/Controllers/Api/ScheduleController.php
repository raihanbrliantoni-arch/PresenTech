<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Schedule;
use App\Models\Student;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScheduleController extends Controller
{
    public function index(Request $request)
    {
        $query = Schedule::with(['schoolClass', 'teacher']);

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->integer('class_id'));
        }

        if ($request->filled('teacher_id')) {
            $query->where('teacher_id', $request->integer('teacher_id'));
        }

        // Filter jadwal berdasarkan akun yang sedang login agar Guru/Siswa
        // selalu mendapatkan jadwal terbaru tanpa bergantung pada localStorage lama.
        if ($request->boolean('mine') && $request->user()) {
            $user = $request->user()->load(['teacher', 'student']);
            if ($user->role === 'guru' && $user->teacher) {
                $query->where('teacher_id', $user->teacher->id);
            } elseif ($user->role === 'siswa' && $user->student) {
                $query->where('class_id', $user->student->class_id);
            }
        }

        if ($request->filled('day')) {
            $query->where('day', $request->string('day'));
        }

        // CASE SQL standar (portable untuk MySQL, PostgreSQL, dan SQLite) untuk
        // mengurutkan hari sesuai urutan minggu sekolah, lalu jam mulai.
        $dayOrderCase = 'CASE day '
            .implode(' ', [
                "WHEN 'Senin' THEN 1", "WHEN 'Selasa' THEN 2", "WHEN 'Rabu' THEN 3",
                "WHEN 'Kamis' THEN 4", "WHEN 'Jumat' THEN 5", "WHEN 'Sabtu' THEN 6",
                "WHEN 'Minggu' THEN 7", 'ELSE 8 END',
            ]);

        return response()->json(
            $query->orderByRaw($dayOrderCase)
                ->orderBy('start_time')
                ->paginate(min(max((int) $request->input('per_page', 20), 1), 1000))
        );
    }

    public function show(Schedule $schedule)
    {
        return response()->json($schedule->load(['schoolClass', 'teacher']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'day' => ['required', Rule::in(['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'])],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'subject' => ['required', 'string', 'max:150'],
            'room' => ['nullable', 'string', 'max:100'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'semester' => ['nullable', Rule::in(['Ganjil', 'Genap'])],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->assertNoConflict($data);

        $schedule = Schedule::create($data);
        $schedule->load(['schoolClass', 'teacher']);
        self::notifySchedule($schedule, 'Jadwal baru');
        Audit::log($request, 'system', 'schedule.created', 'Jadwal ditambahkan: '.$schedule->subject, ['schedule_id' => $schedule->id]);

        return response()->json($schedule, 201);
    }

    public function update(Request $request, Schedule $schedule)
    {
        // Gunakan start_time baru (jika dikirim) atau start_time yang sudah
        // tersimpan, agar end_time tetap tervalidasi saat update parsial.
        $effectiveStart = $request->input('start_time', $schedule->start_time);

        $data = $request->validate([
            'class_id' => ['sometimes', 'integer', 'exists:school_classes,id'],
            'teacher_id' => ['sometimes', 'integer', 'exists:teachers,id'],
            'day' => ['sometimes', Rule::in(['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'])],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i', 'after:'.$effectiveStart],
            'subject' => ['sometimes', 'string', 'max:150'],
            'room' => ['nullable', 'string', 'max:100'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'semester' => ['nullable', Rule::in(['Ganjil', 'Genap'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Bentrok dicek memakai nilai efektif (gabungan data lama + perubahan)
        // agar update parsial tetap akurat.
        $this->assertNoConflict([
            'class_id' => $data['class_id'] ?? $schedule->class_id,
            'teacher_id' => $data['teacher_id'] ?? $schedule->teacher_id,
            'day' => $data['day'] ?? $schedule->day,
            'start_time' => $data['start_time'] ?? $schedule->start_time,
            'end_time' => $data['end_time'] ?? $schedule->end_time,
            'room' => array_key_exists('room', $data) ? $data['room'] : $schedule->room,
            'is_active' => $data['is_active'] ?? $schedule->is_active,
        ], $schedule->id);

        $schedule->update($data);
        $schedule->load(['schoolClass', 'teacher']);
        self::notifySchedule($schedule, 'Jadwal diperbarui');
        Audit::log($request, 'system', 'schedule.updated', 'Jadwal diubah: '.$schedule->subject, ['schedule_id' => $schedule->id]);

        return response()->json($schedule->fresh()->load(['schoolClass', 'teacher']));
    }

    public function destroy(Request $request, Schedule $schedule)
    {
        $label = $schedule->subject;
        $class = $schedule->schoolClass?->name;
        $message = $schedule->day.' '.substr((string) $schedule->start_time, 0, 5).' · '.$label;
        if ($class) {
            Student::with('user')->where('class_id', $schedule->class_id)->whereNotNull('user_id')->get()->each(function ($student) use ($message, $class) {
                Notification::create([
                    'recipient_user_id' => $student->user_id,
                    'recipient_role' => 'siswa',
                    'recipient_class' => $class,
                    'recipient_nis' => $student->nis,
                    'type' => 'schedule',
                    'title' => 'Jadwal dihapus',
                    'message' => $message,
                    'expires_at' => now()->addHours(24),
                ]);
            });
        }
        if ($schedule->teacher?->user_id) {
            Notification::create(['recipient_role' => 'guru', 'recipient_user_id' => $schedule->teacher->user_id, 'type' => 'schedule', 'title' => 'Jadwal dihapus', 'message' => $message, 'expires_at' => now()->addHours(24)]);
        }
        Audit::log($request, 'system', 'schedule.deleted', 'Jadwal dihapus: '.$label, ['schedule_id' => $schedule->id]);
        $schedule->delete();

        return response()->json(['message' => 'Jadwal berhasil dihapus.']);
    }

    /**
     * Tolak jadwal yang bertabrakan pada kelas, guru, atau ruangan yang sama.
     * Dipakai store() dan update() agar data jadwal selalu konsisten.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNoConflict(array $data, ?int $ignoreId = null): void
    {
        if (! ($data['is_active'] ?? true)) {
            return;
        }

        $start = $this->normalizeTime((string) $data['start_time']);
        $end = $this->normalizeTime((string) $data['end_time']);

        $overlapping = fn () => Schedule::query()
            ->where('day', $data['day'])
            ->where('is_active', true)
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId));

        $classConflict = $overlapping()->where('class_id', $data['class_id'])->with('schoolClass')->first();

        if ($classConflict !== null) {
            abort(422, 'Bentrok: kelas '.($classConflict->schoolClass?->name ?? '#'.$classConflict->class_id)
                .' sudah punya '.$classConflict->subject.' pada '.$classConflict->day.' '
                .substr((string) $classConflict->start_time, 0, 5).'-'.substr((string) $classConflict->end_time, 0, 5).'.');
        }

        $teacherConflict = $overlapping()->where('teacher_id', $data['teacher_id'])->with('teacher')->first();

        if ($teacherConflict !== null) {
            abort(422, 'Bentrok: guru '.($teacherConflict->teacher?->name ?? '#'.$teacherConflict->teacher_id)
                .' sudah mengajar '.$teacherConflict->subject.' pada '.$teacherConflict->day.' '
                .substr((string) $teacherConflict->start_time, 0, 5).'-'.substr((string) $teacherConflict->end_time, 0, 5).'.');
        }

        $room = trim((string) ($data['room'] ?? ''));

        if ($room !== '') {
            $roomConflict = $overlapping()->where('room', $room)->with('schoolClass')->first();

            if ($roomConflict !== null) {
                abort(422, 'Bentrok: ruangan '.$room.' sudah dipakai '.($roomConflict->schoolClass?->name ?? '-')
                    .' pada '.$roomConflict->day.' '.substr((string) $roomConflict->start_time, 0, 5).'-'.substr((string) $roomConflict->end_time, 0, 5).'.');
            }
        }
    }

    private function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }

    private static function notifySchedule(Schedule $schedule, string $title): void
    {
        $schedule->loadMissing(['schoolClass', 'teacher']);
        $class = $schedule->schoolClass?->name;
        $message = $schedule->day.' '.substr((string) $schedule->start_time, 0, 5).' · '.$schedule->subject.($schedule->room ? ' · '.$schedule->room : '');

        // Target setiap siswa yang benar-benar berada di kelas tersebut.
        if ($class) {
            Student::with('user')
                ->where('class_id', $schedule->class_id)
                ->whereNotNull('user_id')
                ->get()
                ->each(function ($student) use ($title, $message, $class) {
                    Notification::create([
                        'recipient_user_id' => $student->user_id,
                        'recipient_role' => 'siswa',
                        'recipient_class' => $class,
                        'recipient_nis' => $student->nis,
                        'type' => 'schedule',
                        'title' => $title,
                        'message' => $message,
                        'expires_at' => now()->addHours(24),
                    ]);
                });
        }

        // Target guru yang mengajar jadwal tersebut.
        if ($schedule->teacher?->user_id) {
            Notification::create([
                'recipient_user_id' => $schedule->teacher->user_id,
                'recipient_role' => 'guru',
                'type' => 'schedule',
                'title' => $title,
                'message' => $message,
                'expires_at' => now()->addHours(24),
            ]);
        }
    }
}
