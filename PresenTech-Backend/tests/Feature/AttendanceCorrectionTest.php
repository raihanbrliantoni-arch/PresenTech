<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AttendanceCorrectionTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function setupSiswaDenganJadwal(): array
    {
        $siswa = $this->makeSiswa('siswa1', '10001');
        $class = SchoolClass::create(['name' => 'XI RPL 1', 'major' => 'RPL', 'student_count' => 1]);
        $siswa->student->update(['class_id' => $class->id]);

        $teacher = Teacher::where('user_id', $this->makeGuru('guru-jadwal')->id)->firstOrFail();

        $schedule = Schedule::create([
            'class_id' => $class->id,
            'teacher_id' => $teacher->id,
            'day' => 'Rabu',
            'start_time' => '07:00',
            'end_time' => '08:30',
            'subject' => 'Uji Koreksi',
            'is_active' => true,
        ]);

        return [$siswa->fresh(), $class, $schedule];
    }

    public function test_pengajuan_tanpa_attendance_id_otomatis_tertaut_ke_absensi_siswa(): void
    {
        [$siswa, $class] = $this->setupSiswaDenganJadwal();
        $guru = $this->makeGuru('guru1');

        $attendance = Attendance::create([
            'student_id' => $siswa->student->id,
            'attendance_date' => '2026-10-21',
            'attendance_time' => '06:30:00',
            'status' => 'Alpa',
            'source' => 'Manual',
        ]);

        $response = $this->actingAs($guru)->postJson('/api/attendance-corrections', [
            'student_id' => $siswa->student->id,
            'attendance_date' => '2026-10-21',
            'expected_time' => '07:05:00',
            'reason' => 'Siswa hadir tetapi belum tercatat.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('attendance_id', $attendance->id)
            ->assertJsonPath('status', 'PENDING');
    }

    public function test_persetujuan_koreksi_memperbarui_absensi_yang_tertaut(): void
    {
        [$siswa, $class] = $this->setupSiswaDenganJadwal();
        $guru = $this->makeGuru('guru1');
        $admin = $this->makeAdmin();

        $attendance = Attendance::create([
            'student_id' => $siswa->student->id,
            'attendance_date' => '2026-10-21',
            'attendance_time' => '06:30:00',
            'status' => 'Alpa',
            'source' => 'Manual',
        ]);

        $correctionId = $this->actingAs($guru)->postJson('/api/attendance-corrections', [
            'student_id' => $siswa->student->id,
            'attendance_id' => $attendance->id,
            'attendance_date' => '2026-10-21',
            'expected_time' => '07:05:00',
            'reason' => 'Salah input status.',
        ])->assertCreated()->json('id');

        $this->actingAs($admin)
            ->putJson("/api/attendance-corrections/{$correctionId}", ['status' => 'APPROVED'])
            ->assertOk()
            ->assertJsonPath('status', 'APPROVED')
            ->assertJsonPath('attendance.id', $attendance->id)
            ->assertJsonPath('attendance.status', 'Terlambat')
            ->assertJsonPath('attendance.attendance_time', '07:05:00');
    }

    public function test_persetujuan_koreksi_membuat_absensi_bila_belum_ada(): void
    {
        [$siswa, $class] = $this->setupSiswaDenganJadwal();
        $siswaUser = $siswa;
        $admin = $this->makeAdmin();

        $correctionId = $this->actingAs($siswaUser)->postJson('/api/attendance-corrections', [
            'student_id' => $siswa->student->id,
            'attendance_date' => '2026-10-22',
            'expected_time' => '06:45:00',
            'reason' => 'Tidak tercatat hadir.',
        ])->assertCreated()->json('id');

        $this->actingAs($admin)
            ->putJson("/api/attendance-corrections/{$correctionId}", ['status' => 'APPROVED'])
            ->assertOk()
            ->assertJsonPath('attendance.status', 'Hadir')
            ->assertJsonPath('attendance.attendance_time', '06:45:00')
            ->assertJsonPath('attendance.source', 'Manual');

        $this->assertDatabaseHas('attendances', [
            'student_id' => $siswa->student->id,
            'attendance_time' => '06:45:00',
            'status' => 'Hadir',
        ]);

        $this->assertNotNull(
            Attendance::where('student_id', $siswa->student->id)->first()?->id
        );
    }

    public function test_keputusan_koreksi_tidak_bisa_diulang(): void
    {
        [$siswa] = $this->setupSiswaDenganJadwal();
        $guru = $this->makeGuru('guru1');
        $admin = $this->makeAdmin();

        $correctionId = $this->actingAs($guru)->postJson('/api/attendance-corrections', [
            'student_id' => $siswa->student->id,
            'attendance_date' => '2026-10-21',
            'expected_time' => '07:05:00',
            'reason' => 'Uji keputusan ganda.',
        ])->assertCreated()->json('id');

        $this->actingAs($admin)
            ->putJson("/api/attendance-corrections/{$correctionId}", ['status' => 'APPROVED'])
            ->assertOk();

        $this->actingAs($admin)
            ->putJson("/api/attendance-corrections/{$correctionId}", ['status' => 'REJECTED'])
            ->assertStatus(409);
    }
}
