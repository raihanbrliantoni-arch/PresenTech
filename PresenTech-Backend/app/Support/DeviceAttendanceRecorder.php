<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\RfidCard;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pencatat absensi dari perangkat fisik (ESP32). Logika yang sama dipakai
 * oleh endpoint HTTP AttendanceController@store (jalur X-Device-Key),
 * endpoint sinkron antrean offline, dan bridge MQTT devices:mqtt-listen,
 * sehingga semua transport menghasilkan aturan identik (dedupe,
 * Hadir/Terlambat, serta penandaan waktu yang belum terverifikasi).
 */
final class DeviceAttendanceRecorder
{
    /**
     * Catat absensi untuk UID kartu yang dibaca perangkat.
     * Bentuk hasil meniru respons HTTP agar perangkat (LCD) dan web
     * mendapat data yang konsisten.
     *
     * $clientEventId membuat pencatatan idempoten: kiriman ulang event yang
     * sama (mis. antrean offline yang tersinkron dua kali) tidak menambah
     * baris baru. $tappedAt dipakai untuk event yang sempat tersimpan di
     * perangkat; bila waktunya tidak wajar, waktu server dipakai dan baris
     * ditandai belum terverifikasi.
     *
     * @return array{outcome: string, status_code: int, body: array}
     */
    public function record(
        Device $device,
        ?string $uid,
        ?int $requestedScheduleId = null,
        ?string $clientEventId = null,
        ?Carbon $tappedAt = null,
        bool $timeVerified = true,
    ): array {
        // Kiriman ulang event yang sudah tercatat: anggap selesai (idempoten).
        if ($clientEventId !== null) {
            $existingEvent = Attendance::where('device_id', $device->id)
                ->where('client_event_id', $clientEventId)
                ->first();

            if ($existingEvent) {
                return [
                    'outcome' => 'duplicate',
                    'status_code' => 409,
                    'body' => [
                        'message' => 'Event ini sudah pernah dicatat.',
                        'existing_attendance' => $existingEvent,
                    ],
                ];
            }
        }

        // Perangkat dalam mode Maintenance dianggap tidak beroperasi.
        if ($device->status === 'Maintenance') {
            return [
                'outcome' => 'maintenance',
                'status_code' => 403,
                'body' => ['message' => 'Perangkat sedang dalam mode Maintenance.'],
            ];
        }

        $uid = RfidUid::normalize((string) $uid);

        $card = RfidCard::with('student')
            ->where('uid', $uid)
            ->where('status', 'Aktif')
            ->first();
        $student = $card?->student;

        if (! $student || $student->status !== 'Aktif') {
            return [
                'outcome' => 'unregistered',
                'status_code' => 404,
                'body' => ['message' => 'Siswa tidak ditemukan atau tidak aktif.'],
            ];
        }

        // Waktu: perangkat online memakai jam server. Event dari antrean
        // offline membawa waktu tap sendiri; waktu yang tidak wajar
        // (masa depan / terlalu lama) diganti jam server dan ditandai.
        $effectiveAt = $tappedAt ?? now();

        if ($tappedAt !== null && ($tappedAt->lt(now()->subDays(7)) || $tappedAt->gt(now()->addMinutes(10)))) {
            $effectiveAt = now();
            $timeVerified = false;
        }

        $date = $effectiveAt->toDateString();
        $time = $effectiveAt->format('H:i:s');
        $notes = $timeVerified ? null : 'Waktu kiriman perangkat belum terverifikasi (NTP belum sinkron).';

        // Tap beruntun: beberapa kartu berbeda ditap pada perangkat yang sama
        // dalam hitungan detik. Ini pola umum "titip kartu" dan ditandai agar
        // bisa diverifikasi Guru/Admin, bukan langsung dianggap sah.
        $buddyNote = $this->buddyTapNote($device, $student->id, $date, $effectiveAt);

        // Jadwal hanya boleh dipakai bila milik kelas siswa dan benar-benar
        // aktif pada hari tersebut — perangkat tidak boleh memaksa
        // schedule_id lain untuk memanipulasi status.
        $scheduleId = $requestedScheduleId;
        if ($scheduleId !== null) {
            $schedule = Schedule::find($scheduleId);
            $matchesClass = $schedule && (string) $schedule->class_id === (string) $student->class_id;
            $validForDevice = $schedule !== null && $schedule->is_active && $schedule->day === $this->dayNameFor($date);

            if (! $matchesClass || ! $validForDevice) {
                $scheduleId = null;
            }
        }

        // Cek duplikat + insert dalam SATU transaksi dengan lock baris siswa:
        // dua request bersamaan terserialisasi sehingga tidak bisa lolos cek
        // duplikat secara bersamaan (race condition double-tap).
        $result = DB::transaction(function () use ($device, $student, $card, $date, $time, $scheduleId, $clientEventId, $notes, $buddyNote) {
            Student::whereKey($student->id)->lockForUpdate()->first();

            $existing = Attendance::where('student_id', $student->id)
                ->whereDate('attendance_date', $date)
                ->first();

            // Perangkat selalu dihitung otomatis (Hadir/Terlambat).
            $status = strcmp(substr($time, 0, 5), substr($this->resolveLateThreshold($student, ['schedule_id' => $scheduleId], $date), 0, 5)) > 0
                ? 'Terlambat'
                : 'Hadir';

            if ($existing) {
                // Baris Alpa otomatis (dibuat setelah batas terlambat) boleh
                // diperbarui ketika siswa akhirnya tap.
                if ($existing->auto_alpa) {
                    $existing->update([
                        'rfid_card_id' => $card?->id,
                        'device_id' => $device->id,
                        'schedule_id' => $scheduleId,
                        'attendance_time' => $time,
                        'check_out_time' => null,
                        'early_leave' => false,
                        'status' => $status,
                        'source' => $card ? 'RFID' : 'Device',
                        'uid_snapshot' => $card?->uid,
                        'client_event_id' => $clientEventId,
                        'notes' => $this->mergeNotes($notes, $buddyNote),
                        'auto_alpa' => false,
                    ]);

                    if ($card) {
                        $card->update(['last_used_at' => now()]);
                    }

                    return ['attendance' => $existing->fresh()];
                }

                // Tap kedua pada hari yang sama = pulang, tetapi hanya setelah
                // jam pulang (atau jika Admin sudah mencatat pulang lebih awal).
                if ($existing->check_out_time !== null) {
                    return ['duplicate' => $existing, 'reason' => 'checked_out'];
                }

                if (! $this->canCheckOut($existing, $time)) {
                    return ['duplicate' => $existing, 'reason' => 'too_early'];
                }

                $existing->update([
                    'device_id' => $device->id,
                    'check_out_time' => $time,
                    'notes' => $this->mergeNotes($existing->notes, $buddyNote),
                ]);

                if ($card) {
                    $card->update(['last_used_at' => now()]);
                }

                return ['checked_out' => $existing->fresh()];
            }

            $attendance = Attendance::create([
                'student_id' => $student->id,
                'rfid_card_id' => $card?->id,
                'device_id' => $device->id,
                'schedule_id' => $scheduleId,
                'attendance_date' => $date,
                'attendance_time' => $time,
                'status' => $status,
                'auto_alpa' => false,
                'source' => $card ? 'RFID' : 'Device',
                'uid_snapshot' => $card?->uid,
                'client_event_id' => $clientEventId,
                'notes' => $this->mergeNotes($notes, $buddyNote),
            ]);

            if ($card) {
                $card->update(['last_used_at' => now()]);
            }

            return ['attendance' => $attendance];
        });

        if (isset($result['checked_out'])) {
            return [
                'outcome' => 'checked_out',
                'status_code' => 200,
                'body' => [
                    'message' => 'Absensi pulang berhasil dicatat pukul '.substr($time, 0, 5).'.',
                    'attendance' => $result['checked_out']->load(['student.schoolClass', 'rfidCard', 'device', 'schedule']),
                    'display' => $this->displayFor($student, $result['checked_out']->status, (string) $result['checked_out']->check_out_time),
                ],
            ];
        }

        if (isset($result['duplicate'])) {
            $existing = $result['duplicate'];
            $reason = $result['reason'] ?? null;

            $message = match ($reason) {
                'checked_out' => 'Siswa sudah tap pulang pukul '.substr((string) $existing->check_out_time, 0, 5).'.',
                'too_early' => 'Siswa sudah absen masuk pukul '.substr((string) $existing->attendance_time, 0, 5)
                    .'. Tap pulang dibuka mulai '.Setting::current()->timesForDate($existing->attendance_date)['home_time'].'.',
                default => 'Siswa sudah tercatat absensi pada tanggal ini.',
            };

            return [
                'outcome' => 'duplicate',
                'status_code' => 409,
                'body' => [
                    'message' => $message,
                    'student' => $student,
                    'existing_attendance' => $existing->load(['student.schoolClass', 'rfidCard', 'device', 'schedule']),
                    'display' => $this->displayFor($student, $existing->status, (string) ($existing->check_out_time ?: $existing->attendance_time)),
                ],
            ];
        }

        return [
            'outcome' => 'created',
            'status_code' => 201,
            'body' => [
                'message' => 'Absensi berhasil dicatat.',
                'attendance' => $result['attendance']->load(['student.schoolClass', 'rfidCard', 'device', 'schedule']),
                'display' => $this->displayFor($student, $result['attendance']->status, $time),
            ],
        ];
    }

    /**
     * Teks siap tampil di layar alat setelah tap kartu. Nama siswa panjang
     * dipendekkan di server supaya alat tidak perlu memotong kata sendiri;
     * field tambahan ini opsional sehingga firmware lama tetap aman.
     *
     * @return array{student_name: string, student_name_initials: string, class_name: string|null, status: string, time: string|null}
     */
    private function displayFor(Student $student, ?string $status, ?string $time): array
    {
        $className = $student->schoolClass?->name;

        return [
            'student_name' => DeviceDisplay::shortPersonName($student->name, 16),
            'student_name_initials' => DeviceDisplay::initialsName($student->name, 16),
            'class_name' => $className ? DeviceDisplay::shorten($className, 16, '') : null,
            'status' => (string) $status,
            'time' => $time ? substr($time, 0, 5) : null,
        ];
    }

    /**
     * Tap kedua baru sah sebagai pulang setelah jam pulang di Pengaturan,
     * dengan jarak minimal dari jam masuk agar tap ganda tidak terbaca pulang.
     */
    private function canCheckOut(Attendance $existing, string $time): bool
    {
        if (! in_array($existing->status, ['Hadir', 'Terlambat'], true)) {
            return false;
        }

        $homeTime = Setting::current()->timesForDate($existing->attendance_date)['home_time'];

        if (strcmp(substr($time, 0, 5), $homeTime) < 0) {
            return false;
        }

        $minGap = max(0, (int) config('presentech.attendance.check_out_min_gap_minutes', 10));

        return abs(Carbon::parse($time)->diffInMinutes(Carbon::parse((string) $existing->attendance_time))) >= $minGap;
    }

    /**
     * Tandai tap berurutan dari kartu berbeda pada perangkat yang sama.
     * $at dipakai agar event antrean offline dinilai dengan waktunya sendiri.
     */
    private function buddyTapNote(Device $device, int $studentId, string $date, Carbon $at): ?string
    {
        $window = (int) config('presentech.attendance.buddy_tap_window_seconds', 5);

        if ($window <= 0) {
            return null;
        }

        $from = $at->copy()->subSeconds($window)->format('H:i:s');
        $to = $at->format('H:i:s');

        $recent = Attendance::query()
            ->where('device_id', $device->id)
            ->whereDate('attendance_date', $date)
            ->where('student_id', '!=', $studentId)
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('attendance_time', [$from, $to])
                    ->orWhereBetween('check_out_time', [$from, $to]);
            })
            ->exists();

        return $recent
            ? "Tap beruntun di perangkat yang sama (<= {$window} detik) — perlu verifikasi."
            : null;
    }

    private function mergeNotes(?string ...$parts): ?string
    {
        $merged = trim(implode(' ', array_filter(array_map('trim', $parts))));

        return $merged === '' ? null : $merged;
    }

    public function dayNameFor(string $date): ?string
    {
        $dayMap = [
            'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat',
            'Saturday' => 'Sabtu',
        ];

        return $dayMap[Carbon::parse($date)->format('l')] ?? null;
    }

    /**
     * Batas jam masuk: pakai jadwal kelas siswa pada hari tersebut
     * (mulai jam pelajaran pertama). Jika tidak ada jadwal, pakai default config.
     */
    public function resolveLateThreshold(Student $student, ?array $data, string $date): string
    {
        if ($data !== null && ! empty($data['schedule_id'])) {
            $schedule = Schedule::find($data['schedule_id']);
            if ($schedule?->start_time) {
                return (string) $schedule->start_time;
            }
        }

        $day = $this->dayNameFor($date);

        if ($student->class_id && $day) {
            $schedule = Schedule::query()
                ->where('class_id', $student->class_id)
                ->where('day', $day)
                ->where('is_active', true)
                ->orderBy('start_time')
                ->first();

            if ($schedule?->start_time) {
                return (string) $schedule->start_time;
            }
        }

        // Tanpa jadwal: batas keterlambatan mengikuti pengaturan sekolah
        // untuk hari tersebut (fallback terakhir tetap config).
        return Setting::current()->timesForDate($date)['late_time']
            ?: config('presentech.attendance.late_threshold', '07:15');
    }
}
