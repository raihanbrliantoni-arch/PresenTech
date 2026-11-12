<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureDeviceKey;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\RfidCard;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\Student;
use App\Support\DeviceAttendanceRecorder;
use App\Support\DeviceRuntime;
use App\Support\RfidUid;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    public function __construct(private readonly DeviceAttendanceRecorder $recorder) {}

    public function index(Request $request)
    {
        // Validasi lebih dulu: `?date=abc` sebelumnya melempar 500 karena
        // Carbon::parse dipakai langsung oleh $request->date().
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'status' => ['nullable', Rule::in(['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpa', 'Dikoreksi'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $query = Attendance::with(['student.schoolClass', 'rfidCard', 'device', 'schedule']);

        // Siswa hanya boleh melihat absensi miliknya sendiri.
        $user = $request->user();
        if ($user && $user->role === 'siswa') {
            $ownId = $user->student?->id;
            if (! $ownId) {
                return response()->json(['data' => [], 'meta' => ['pagination' => ['total' => 0]]]);
            }
            if (isset($data['student_id']) && (int) $data['student_id'] !== $ownId) {
                abort(403, 'Siswa hanya boleh melihat absensi miliknya sendiri.');
            }
            $query->where('student_id', $ownId);
        }

        if (! empty($data['date'])) {
            $query->whereDate('attendance_date', $data['date']);
        }

        if (! empty($data['student_id'])) {
            $query->where('student_id', (int) $data['student_id']);
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return response()->json($query->latest('attendance_date')->latest('attendance_time')->paginate($data['per_page'] ?? 50));
    }

    public function show(Request $request, Attendance $attendance)
    {
        $user = $request->user();
        if ($user && $user->role === 'siswa') {
            abort_unless($user->student?->id === $attendance->student_id, 403, 'Siswa hanya boleh melihat absensi miliknya sendiri.');
        }

        return response()->json($attendance->load(['student.schoolClass', 'rfidCard', 'device', 'schedule', 'corrections']));
    }

    /**
     * Endpoint utama untuk ESP32/RFID (header X-Device-Key)
     * maupun absensi manual oleh Admin/Guru (Bearer token).
     * Kirim UID kartu dan, opsional, device_id/schedule_id.
     */
    public function store(Request $request)
    {
        // Autentikasi perangkat fisik sudah dilakukan middleware device.key;
        // jika tidak ada perangkat, request ini dari sesi Admin/Guru.
        $device = $request->attributes->get(EnsureDeviceKey::REQUEST_ATTRIBUTE);

        if (! $device instanceof Device) {
            $device = EnsureDeviceKey::authenticate($request);
        }

        if ($device instanceof Device) {
            if ($request->filled('uid')) {
                $request->merge(['uid' => RfidUid::normalize($request->input('uid'))]);
            }

            $data = $request->validate([
                'uid' => ['nullable', 'string', 'max:64'],
                'nis' => ['nullable', 'string', 'max:30', 'exists:students,nis'],
                'student_id' => ['nullable', 'integer', 'exists:students,id'],
                'device_id' => ['nullable', 'integer', 'exists:devices,id'],
                'schedule_id' => ['nullable', 'integer', 'exists:schedules,id'],
                'attendance_date' => ['nullable', 'date_format:Y-m-d'],
                'attendance_time' => ['nullable', 'date_format:H:i:s'],
                'status' => ['nullable', Rule::in(['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpa', 'Dikoreksi'])],
                'notes' => ['nullable', 'string'],
            ]);

            // Jalur perangkat fisik/RFID: logika dipakai bersama bridge MQTT
            // (DeviceAttendanceRecorder) sehingga aturan tetap identik di
            // kedua transport. Maintenance/dedupe/status dihitung di sana.
            $result = $this->recorder->record($device, $data['uid'] ?? null, $data['schedule_id'] ?? null);

            // Jam pulang dan status tap pulang ikut dikirim agar LCD alat
            // berperilaku sama dengan mode MQTT.
            return response()->json(array_merge(
                $result['body'],
                DeviceRuntime::attendanceWindow(),
                ['outcome' => $result['outcome']],
            ), $result['status_code']);
        }

        $user = $request->user('sanctum');
        abort_unless($user !== null && in_array($user->role, ['admin', 'guru'], true), 401, 'Pencatatan absensi memerlukan device key atau sesi Admin/Guru.');

        if ($request->filled('uid')) {
            $request->merge(['uid' => RfidUid::normalize($request->input('uid'))]);
        }

        $data = $request->validate([
            'uid' => ['nullable', 'string', 'max:64'],
            'nis' => ['nullable', 'string', 'max:30', 'exists:students,nis'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'schedule_id' => ['nullable', 'integer', 'exists:schedules,id'],
            'attendance_date' => ['nullable', 'date_format:Y-m-d'],
            'attendance_time' => ['nullable', 'date_format:H:i:s'],
            'check_out_time' => ['nullable', 'date_format:H:i:s'],
            'early_leave' => ['sometimes', 'boolean'],
            'status' => ['nullable', Rule::in(['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpa', 'Dikoreksi'])],
            'notes' => ['nullable', 'string'],
        ]);

        $card = null;
        $student = null;
        if (! empty($data['uid'])) {
            $card = RfidCard::with('student')->where('uid', $data['uid'])->where('status', 'Aktif')->first();
            $student = $card?->student;
        } elseif (! empty($data['student_id'])) {
            $student = Student::find($data['student_id']);
        } elseif (! empty($data['nis'])) {
            $student = Student::where('nis', $data['nis'])->first();
        }

        if (! $student || $student->status !== 'Aktif') {
            return response()->json(['message' => 'Siswa tidak ditemukan atau tidak aktif.'], 404);
        }

        // Sesi Admin/Guru boleh mencatat absensi manual dengan tanggal/jam
        // tertentu; tanpa isi, waktu memakai jam server.
        $date = $data['attendance_date'] ?? now()->toDateString();
        $time = $data['attendance_time'] ?? now()->format('H:i:s');

        // Pulang lebih awal (izin) dicatat manual oleh Admin/Guru: jam pulang
        // sebelum jam pulang resmi otomatis ditandai early_leave.
        $checkOutTime = $data['check_out_time'] ?? null;
        $earlyLeave = (bool) ($data['early_leave'] ?? false);

        if ($checkOutTime !== null) {
            $homeTime = Setting::current()->timesForDate($date)['home_time'];

            if (strcmp(substr($checkOutTime, 0, 5), $homeTime) < 0) {
                $earlyLeave = true;
            }
        }

        // Jadwal manual hanya boleh dipakai bila milik kelas siswa.
        $scheduleId = $data['schedule_id'] ?? null;
        if ($scheduleId !== null) {
            $schedule = Schedule::find($scheduleId);
            $matchesClass = $schedule && (string) $schedule->class_id === (string) $student->class_id;

            if (! $matchesClass) {
                $scheduleId = null;
            }
        }
        $data['schedule_id'] = $scheduleId;

        // Cek duplikat + insert dalam SATU transaksi dengan lock baris siswa:
        // dua request bersamaan terserialisasi sehingga tidak bisa lolos cek
        // duplikat secara bersamaan (race condition double-tap).
        $result = DB::transaction(function () use ($student, $card, $data, $date, $time, $checkOutTime, $earlyLeave, $scheduleId) {
            Student::whereKey($student->id)->lockForUpdate()->first();

            $existingRow = Attendance::where('student_id', $student->id)
                ->whereDate('attendance_date', $date)
                ->first();

            // Sudah ada absensi masuk pada tanggal itu dan Admin/Guru mengisi
            // jam pulang: lengkapi baris yang sama (mis. pulang lebih awal),
            // jangan buat baris kedua.
            if ($existingRow && $checkOutTime !== null) {
                $existingRow->update([
                    'check_out_time' => $checkOutTime,
                    'early_leave' => $earlyLeave,
                    'notes' => $data['notes'] ?? $existingRow->notes,
                ]);

                return ['attendance' => $existingRow->fresh(), 'completed_checkout' => true];
            }

            // Absensi manual: pakai jendela waktu (config duplicate_window).
            $window = max(1, (int) config('presentech.attendance.duplicate_window', 60));
            $windowStart = Carbon::parse($time)->subSeconds($window)->format('H:i:s');
            $duplicate = Attendance::where('student_id', $student->id)
                ->whereDate('attendance_date', $date)
                ->whereBetween('attendance_time', [$windowStart, $time])
                ->exists();

            if ($duplicate) {
                return ['duplicate' => true];
            }

            // Hanya sesi Admin/Guru manual yang boleh menentukan status.
            $status = ! empty($data['status'])
                ? $data['status']
                : (strcmp(substr($time, 0, 5), substr($this->recorder->resolveLateThreshold($student, $data, $date), 0, 5)) > 0
                    ? 'Terlambat'
                    : 'Hadir');

            $attendance = Attendance::create([
                'student_id' => $student->id,
                'rfid_card_id' => $card?->id,
                'device_id' => $data['device_id'] ?? null,
                'schedule_id' => $scheduleId,
                'attendance_date' => $date,
                'attendance_time' => $time,
                'check_out_time' => $checkOutTime,
                'early_leave' => $earlyLeave,
                'status' => $status,
                'source' => $card ? 'RFID' : 'Manual',
                'uid_snapshot' => $card?->uid,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($card) {
                $card->update(['last_used_at' => now()]);
            }

            return ['attendance' => $attendance];
        });

        if (isset($result['duplicate'])) {
            return response()->json([
                'message' => 'Absensi terdeteksi sebagai double tap.',
                'student' => $student,
            ], 409);
        }

        return response()->json([
            'message' => isset($result['completed_checkout'])
                ? 'Jam pulang berhasil dicatat.'
                : 'Absensi berhasil dicatat.',
            'attendance' => $result['attendance']->load(['student.schoolClass', 'rfidCard', 'device', 'schedule']),
        ], isset($result['completed_checkout']) ? 200 : 201);
    }

    /**
     * Sinkronisasi antrean absensi offline dari perangkat (X-Device-Key).
     * Selalu 200 bila autentikasi valid; tiap event dilaporkan sendiri
     * sehingga perangkat hanya menghapus event yang sudah diproses.
     */
    public function storeBatch(Request $request)
    {
        $device = $request->attributes->get(EnsureDeviceKey::REQUEST_ATTRIBUTE);
        abort_unless($device instanceof Device, 401, 'Device key tidak valid.');

        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.client_event_id' => ['required', 'string', 'max:64', 'distinct'],
            'events.*.uid' => ['required', 'string', 'max:64'],
            'events.*.tapped_at' => ['nullable', 'date'],
            'events.*.time_verified' => ['sometimes', 'boolean'],
            'events.*.schedule_id' => ['nullable', 'integer', 'exists:schedules,id'],
        ]);

        $results = [];

        foreach ($data['events'] as $event) {
            $result = $this->recorder->record(
                $device,
                $event['uid'],
                $event['schedule_id'] ?? null,
                $event['client_event_id'],
                ! empty($event['tapped_at']) ? Carbon::parse($event['tapped_at']) : null,
                (bool) ($event['time_verified'] ?? true),
            );

            $results[] = [
                'client_event_id' => $event['client_event_id'],
                'outcome' => $result['outcome'],
                'status_code' => $result['status_code'],
                'attendance_id' => $result['body']['attendance']['id']
                    ?? $result['body']['existing_attendance']['id']
                    ?? null,
            ];
        }

        return response()->json(['results' => $results]);
    }

    public function update(Request $request, Attendance $attendance)
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpa', 'Dikoreksi'])],
            'attendance_date' => ['sometimes', 'date_format:Y-m-d'],
            'attendance_time' => ['sometimes', 'date_format:H:i:s'],
            'check_out_time' => ['nullable', 'date_format:H:i:s'],
            'early_leave' => ['sometimes', 'boolean'],
            'schedule_id' => ['nullable', 'integer', 'exists:schedules,id'],
            'notes' => ['nullable', 'string'],
        ]);

        // Menetapkan jam pulang manual: tandai otomatis bila lebih awal dari
        // jam pulang resmi, kecuali Admin/Guru mengirim flag eksplisit.
        if (array_key_exists('check_out_time', $data) && $data['check_out_time'] !== null && ! array_key_exists('early_leave', $data)) {
            $date = $data['attendance_date'] ?? $attendance->attendance_date;
            $homeTime = Setting::current()->timesForDate($date)['home_time'];
            $data['early_leave'] = strcmp(substr($data['check_out_time'], 0, 5), $homeTime) < 0;
        }

        $attendance->update($data);

        return response()->json($attendance->fresh()->load(['student.schoolClass', 'rfidCard', 'device', 'schedule']));
    }

    public function destroy(Attendance $attendance)
    {
        $attendance->delete();

        return response()->json(['message' => 'Data absensi berhasil dihapus.']);
    }
}
