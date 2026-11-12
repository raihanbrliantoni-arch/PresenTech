<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DataGuardTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_kelas_yang_masih_berisi_siswa_tak_dapat_dihapus(): void
    {
        $admin = $this->makeAdmin();
        $class = SchoolClass::create(['name' => 'XI RPL 1', 'major' => 'RPL', 'student_count' => 1]);
        Student::create(['class_id' => $class->id, 'nis' => '10001', 'name' => 'Siswa A', 'status' => 'Aktif']);

        $this->actingAs($admin)
            ->deleteJson('/api/classes/'.$class->id)
            ->assertConflict();

        $this->assertNotNull($class->fresh());

        // Kelas kosong tetap bisa dihapus.
        $empty = SchoolClass::create(['name' => 'XII RPL 7', 'major' => 'RPL', 'student_count' => 0]);
        $this->actingAs($admin)
            ->deleteJson('/api/classes/'.$empty->id)
            ->assertOk();
    }

    public function test_siswa_dengan_riwayat_absensi_tak_dapat_dihapus(): void
    {
        $admin = $this->makeAdmin();
        [, $student] = $this->siswaBaru('10001', $admin);

        Attendance::create([
            'student_id' => $student->id,
            'attendance_date' => '2026-10-21',
            'attendance_time' => '07:00:00',
            'status' => 'Hadir',
            'source' => 'Manual',
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/students/'.$student->id)
            ->assertConflict();

        // Siswa tanpa riwayat tetap bisa dihapus.
        [, $studentBaru] = $this->siswaBaru('10002', $admin);
        $this->actingAs($admin)
            ->deleteJson('/api/students/'.$studentBaru->id)
            ->assertOk();
    }

    public function test_guru_dengan_jadwal_tak_dapat_dihapus(): void
    {
        $admin = $this->makeAdmin();
        $guru = $this->makeGuru('guru1');
        $class = SchoolClass::create(['name' => 'X RPL 1', 'major' => 'RPL', 'student_count' => 0]);

        Schedule::create([
            'class_id' => $class->id,
            'teacher_id' => $guru->teacher->id,
            'day' => 'Senin',
            'start_time' => '07:00',
            'end_time' => '08:30',
            'subject' => 'Matematika',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/teachers/'.$guru->teacher->id)
            ->assertConflict();

        // Guru tanpa jadwal tetap bisa dihapus.
        $this->actingAs($admin)
            ->deleteJson('/api/teachers/'.$this->makeGuru('guru2')->teacher->id)
            ->assertOk();
    }

    public function test_guru_dengan_pengajuan_koreksi_tak_dapat_dihapus(): void
    {
        $admin = $this->makeAdmin();
        $guru = $this->makeGuru('guruK');
        [, $siswa] = $this->siswaBaru('10009', $admin);

        AttendanceCorrection::create([
            'student_id' => $siswa->id,
            'requested_by' => $guru->id,
            'attendance_date' => '2026-10-21',
            'reason' => 'Lupa tap',
            'status' => 'PENDING',
        ]);

        $teacherId = $guru->teacher->id;

        $this->actingAs($admin)
            ->deleteJson('/api/teachers/'.$teacherId)
            ->assertConflict();

        $this->assertNotNull($teacher = Teacher::find($teacherId));
        $this->assertSame('guruK', $teacher->user?->username);
    }

    public function test_koreksi_yang_sudah_diputuskan_tidak_bisa_diubah_atau_ditarik(): void
    {
        $admin = $this->makeAdmin();
        [, $siswa] = $this->siswaBaru('10001', $admin);

        $attendance = Attendance::create([
            'student_id' => $siswa->id,
            'attendance_date' => '2026-10-21',
            'attendance_time' => '07:00:00',
            'status' => 'Hadir',
            'source' => 'Manual',
        ]);

        $correction = AttendanceCorrection::create([
            'attendance_id' => $attendance->id,
            'student_id' => $siswa->id,
            'requested_by' => $admin->id,
            'attendance_date' => '2026-10-21',
            'expected_time' => '07:30:00',
            'reason' => 'Jam tidak tercatat',
            'status' => 'PENDING',
        ]);

        // Setujui: status dihitung ulang dari jam koreksi (07:30 melewati
        // batas 07:15) dan jam absensi ikut diperbarui.
        $this->actingAs($admin)
            ->putJson('/api/attendance-corrections/'.$correction->id, [
                'status' => 'APPROVED',
            ])
            ->assertOk();

        $attendance->refresh();
        $this->assertSame('Terlambat', $attendance->status);
        $this->assertSame('07:30:00', substr((string) $attendance->attendance_time, 0, 8));

        // Keputusan yang sudah dibuat tidak bisa diulang/dibalik.
        $this->actingAs($admin)
            ->putJson('/api/attendance-corrections/'.$correction->id, [
                'status' => 'REJECTED',
            ])
            ->assertConflict();

        // Pemilik non-admin tidak bisa menarik pengajuan yang sudah diputuskan.
        $guru = $this->makeGuru('guru2');
        [, $siswa2] = $this->siswaBaru('10002', $admin);
        Attendance::create([
            'student_id' => $siswa2->id,
            'attendance_date' => '2026-10-21',
            'attendance_time' => '07:00:00',
            'status' => 'Hadir',
            'source' => 'Manual',
        ]);
        $correction2 = AttendanceCorrection::create([
            'student_id' => $siswa2->id,
            'requested_by' => $guru->id,
            'attendance_date' => '2026-10-21',
            'reason' => 'Lupa tap',
            'status' => 'PENDING',
        ]);

        // Pemilik masih boleh menarik pengajuan yang belum diproses.
        $this->actingAs($guru)
            ->deleteJson('/api/attendance-corrections/'.$correction2->id)
            ->assertOk();

        $correction3 = AttendanceCorrection::create([
            'student_id' => $siswa2->id,
            'requested_by' => $guru->id,
            'attendance_date' => '2026-10-21',
            'reason' => 'Lupa tap (ulang)',
            'status' => 'PENDING',
        ]);
        $this->actingAs($admin)
            ->putJson('/api/attendance-corrections/'.$correction3->id, [
                'status' => 'REJECTED',
            ])
            ->assertOk();

        // Setelah diputuskan Admin, pemilik tidak bisa menarik lagi.
        $this->actingAs($guru)
            ->deleteJson('/api/attendance-corrections/'.$correction3->id)
            ->assertConflict();
    }

    public function test_koreksi_dengan_absensi_milik_siswa_lain_ditolak(): void
    {
        $admin = $this->makeAdmin();
        [, $siswaA] = $this->siswaBaru('10001', $admin);
        [, $siswaB] = $this->siswaBaru('10002', $admin);

        $attendanceB = Attendance::create([
            'student_id' => $siswaB->id,
            'attendance_date' => '2026-10-21',
            'attendance_time' => '07:00:00',
            'status' => 'Hadir',
            'source' => 'Manual',
        ]);

        // Mengajukan koreksi untuk siswa A dengan absensi milik siswa B.
        $this->actingAs($admin)
            ->postJson('/api/attendance-corrections', [
                'attendance_id' => $attendanceB->id,
                'student_id' => $siswaA->id,
                'attendance_date' => '2026-10-21',
                'reason' => 'Coba rujuk absensi orang lain',
            ])
            ->assertUnprocessable();
    }

    private function siswaBaru(string $nis, User $admin): array
    {
        $class = SchoolClass::create(['name' => 'X RPL '.$nis, 'major' => 'RPL', 'student_count' => 1]);
        $user = $this->makeUser('siswa', 'user'.$nis, 'siswa12345');
        $student = Student::create([
            'user_id' => $user->id,
            'class_id' => $class->id,
            'nis' => $nis,
            'name' => 'Siswa '.$nis,
            'status' => 'Aktif',
        ]);

        return [$class, $student, $user];
    }
}
