<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Notification;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\Student;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceCorrectionController extends Controller
{
    public function index(Request $request)
    {
        $query = AttendanceCorrection::with(['student.schoolClass', 'requester', 'reviewer']);

        // Siswa hanya boleh melihat koreksi miliknya sendiri.
        $user = $request->user();
        if ($user && $user->role === 'siswa') {
            $ownId = $user->student?->id;
            if (! $ownId) {
                return response()->json(['data' => [], 'meta' => ['pagination' => ['total' => 0]]]);
            }
            $query->where('student_id', $ownId);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->integer('student_id'));
        }

        return response()->json($query->latest()->paginate(min(max((int) $request->input('per_page', 20), 1), 1000)));
    }

    public function show(Request $request, AttendanceCorrection $attendanceCorrection)
    {
        $user = $request->user();
        if ($user && $user->role === 'siswa') {
            abort_unless($user->student?->id === $attendanceCorrection->student_id, 403, 'Koreksi ini bukan milikmu.');
        }

        return response()->json(
            $attendanceCorrection->load(['attendance', 'student.schoolClass', 'requester', 'reviewer'])
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'attendance_id' => ['nullable', 'integer', 'exists:attendances,id'],
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'requested_by' => ['nullable', 'integer', 'exists:users,id'],
            'attendance_date' => ['required', 'date_format:Y-m-d'],
            'expected_time' => ['nullable', 'date_format:H:i:s'],
            'actual_time' => ['nullable', 'date_format:H:i:s'],
            'reason' => ['required', 'string'],
        ]);

        $user = $request->user();
        abort_unless($user, 401);
        $data['requested_by'] = $user->id;
        $data['status'] = 'PENDING';

        // Siswa hanya boleh mengajukan koreksi miliknya sendiri; Guru/Admin boleh mengajukan untuk kebutuhan pengelolaan.
        if ($user->role === 'siswa') {
            abort_unless($user->student?->id === (int) $data['student_id'], 403);
        }

        // Absensi yang dirujuk harus benar-benar milik siswa yang diajukan,
        // agar pengajuan tidak bisa menyentuh absensi siswa lain.
        if (! empty($data['attendance_id'])) {
            $belongs = Attendance::whereKey($data['attendance_id'])
                ->where('student_id', $data['student_id'])
                ->exists();
            if (! $belongs) {
                throw ValidationException::withMessages([
                    'attendance_id' => ['Absensi yang dirujuk bukan milik siswa yang diajukan.'],
                ]);
            }
        } else {
            // Kompatibilitas klien lama: tautkan otomatis ke absensi siswa
            // pada tanggal tersebut (yang paling akhir) agar persetujuan
            // Admin benar-benar memperbarui data absensi, bukan hanya
            // menyimpan catatan koreksi.
            $data['attendance_id'] = Attendance::where('student_id', $data['student_id'])
                ->whereDate('attendance_date', $data['attendance_date'])
                ->orderByDesc('attendance_time')
                ->value('id');
        }

        $correction = AttendanceCorrection::create($data);
        Notification::create(['recipient_role' => 'admin', 'type' => 'correction', 'title' => 'Pengajuan koreksi baru', 'message' => $correction->student->name.' mengajukan koreksi '.$correction->attendance_date, 'expires_at' => now()->addHours(24)]);
        Audit::log($request, 'system', 'correction.created', 'Pengajuan koreksi dibuat', ['correction_id' => $correction->id]);

        return response()->json(
            $correction->load(['student.schoolClass', 'requester']),
            201
        );
    }

    public function update(Request $request, AttendanceCorrection $attendanceCorrection)
    {
        abort_unless($request->user()?->role === 'admin', 403);

        // Keputusan hanya boleh mengalir dari PENDING: pengajuan yang sudah
        // diputuskan tidak bisa diulang, dibalik, atau dikembalikan —
        // mencegah side-effect ganda (absensi berubah lagi, notifikasi spam).
        if ($attendanceCorrection->status !== 'PENDING') {
            return response()->json([
                'message' => 'Pengajuan ini sudah diproses dan tidak bisa diubah lagi.',
            ], 409);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['APPROVED', 'REJECTED'])],
            'reviewed_by' => ['nullable', 'integer', 'exists:users,id'],
            'review_note' => ['nullable', 'string'],
        ]);

        $data['reviewed_at'] = now();

        // Reviewer selalu diambil dari sesi Admin yang sedang login,
        // bukan dari input client.
        $data['reviewed_by'] = $request->user()->id;

        $attendanceCorrection->update($data);

        // Jika disetujui, terapkan waktu hasil koreksi ke absensi asli.
        // Bila belum ada baris absensi (mis. siswa belum tercatat hadir),
        // buat baris baru agar koreksi tidak berhenti sebagai catatan saja.
        if ($data['status'] === 'APPROVED') {
            $student = $attendanceCorrection->student;
            $attendance = $attendanceCorrection->attendance;
            $appliedTime = $attendanceCorrection->actual_time
                ?? $attendanceCorrection->expected_time
                ?? $attendance?->attendance_time;
            $date = Carbon::parse($attendanceCorrection->attendance_date)->toDateString();

            if ($student && $appliedTime) {
                $status = $this->resolveCorrectionStatus($student, $date, (string) $appliedTime, $attendance?->schedule_id);

                if ($attendance) {
                    $attendance->update([
                        'attendance_time' => $appliedTime,
                        'status' => $status,
                    ]);
                } else {
                    $attendance = Attendance::create([
                        'student_id' => $student->id,
                        'schedule_id' => null,
                        'attendance_date' => $date,
                        'attendance_time' => $appliedTime,
                        'status' => $status,
                        'source' => 'Manual',
                        'notes' => 'Dibuat dari koreksi #'.$attendanceCorrection->id,
                    ]);
                    $attendanceCorrection->update(['attendance_id' => $attendance->id]);
                }
            }
        }
        if ($attendanceCorrection->student?->user_id) {
            Notification::create(['recipient_user_id' => $attendanceCorrection->student->user_id, 'recipient_role' => 'siswa', 'type' => 'correction', 'title' => 'Koreksi absensi '.($data['status'] === 'APPROVED' ? 'disetujui' : 'ditolak'), 'message' => $attendanceCorrection->attendance_date.' · '.$attendanceCorrection->reason, 'expires_at' => now()->addHours(24)]);
        }
        Audit::log($request, 'system', 'correction.'.strtolower($data['status']), 'Koreksi '.$data['status'].': '.($attendanceCorrection->student?->name ?? '-'), ['correction_id' => $attendanceCorrection->id]);

        return response()->json(
            $attendanceCorrection->fresh()->load(['attendance', 'student.schoolClass', 'requester', 'reviewer'])
        );
    }

    public function destroy(Request $request, AttendanceCorrection $attendanceCorrection)
    {
        $user = $request->user();
        $isOwner = $attendanceCorrection->requested_by === $user?->id;
        $allowed = $user?->role === 'admin' || $isOwner;
        abort_unless($allowed, 403);

        // Pemilik hanya boleh menarik pengajuan yang masih PENDING;
        // keputusan yang sudah dibuat Admin harus tersimpan (audit trail).
        if ($isOwner && $user->role !== 'admin' && $attendanceCorrection->status !== 'PENDING') {
            return response()->json([
                'message' => 'Pengajuan yang sudah diproses Admin tidak bisa dihapus.',
            ], 409);
        }

        $attendanceCorrection->delete();

        return response()->json(['message' => 'Pengajuan koreksi berhasil dihapus.']);
    }

    private function dayNameFor(string $date): ?string
    {
        $dayMap = [
            'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat',
            'Saturday' => 'Sabtu',
        ];

        return $dayMap[Carbon::parse($date)->format('l')] ?? null;
    }

    /**
     * Status hasil koreksi mengikuti aturan absensi biasa: jam masuk
     * dibandingkan dengan jam mulai jadwal, atau pengaturan late_time
     * bila siswa belum punya jadwal pada hari tersebut.
     */
    private function resolveCorrectionStatus(Student $student, string $date, string $time, ?int $scheduleId = null): string
    {
        $threshold = null;

        if ($scheduleId) {
            $threshold = Schedule::whereKey($scheduleId)->value('start_time');
        }

        $day = $this->dayNameFor($date);

        if (! $threshold && $student->class_id && $day) {
            $threshold = Schedule::query()
                ->where('class_id', $student->class_id)
                ->where('day', $day)
                ->where('is_active', true)
                ->orderBy('start_time')
                ->value('start_time');
        }

        $threshold = $threshold ?: (Setting::current()->timesForDate($date)['late_time']
            ?: config('presentech.attendance.late_threshold', '07:15'));

        return strcmp(substr($time, 0, 5), substr((string) $threshold, 0, 5)) > 0
            ? 'Terlambat'
            : 'Hadir';
    }
}
