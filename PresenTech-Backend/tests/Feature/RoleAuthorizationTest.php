<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\SchoolClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_tamu_tanpa_token_ditolak(): void
    {
        $this->getJson('/api/students')->assertUnauthorized();
        $this->getJson('/api/teachers')->assertUnauthorized();
        $this->getJson('/api/devices')->assertUnauthorized();
        $this->getJson('/api/system-logs')->assertUnauthorized();
    }

    public function test_siswa_tidak_bisa_crud_data_guru_dan_siswa(): void
    {
        $siswa = $this->makeSiswa('siswa1', '10001');
        $guru = $this->makeGuru('guru1');

        $this->actingAs($siswa)->postJson('/api/students', [
            'nis' => '99999',
            'name' => 'Siswa Nakal',
        ])->assertForbidden();

        $this->actingAs($siswa)->getJson('/api/teachers')->assertForbidden();
        $this->actingAs($siswa)->getJson('/api/teachers/'.$guru->teacher->id)->assertForbidden();
        $this->actingAs($siswa)->postJson('/api/teachers', [
            'nip' => '123',
            'name' => 'Guru Palsu',
        ])->assertForbidden();
    }

    public function test_siswa_tidak_bisa_kelola_perangkat_jadwal_dan_log(): void
    {
        $siswa = $this->makeSiswa('siswa1', '10001');

        $this->actingAs($siswa)->getJson('/api/devices')->assertForbidden();
        $this->actingAs($siswa)->postJson('/api/devices', [
            'device_id' => 'ESP-HACK',
            'name' => 'Perangkat Palsu',
        ])->assertForbidden();
        $this->actingAs($siswa)->getJson('/api/system-logs')->assertForbidden();
        $this->actingAs($siswa)->postJson('/api/schedules', [
            'class_id' => 1,
            'teacher_id' => 1,
            'day' => 'Senin',
            'start_time' => '07:00',
            'end_time' => '08:00',
            'subject' => 'Hacking',
        ])->assertForbidden();
    }

    public function test_siswa_hanya_melihat_absensi_miliknya(): void
    {
        $siswaA = $this->makeSiswa('siswaa', '10001');
        $siswaB = $this->makeSiswa('siswab', '10002');

        $attendanceA = Attendance::create([
            'student_id' => $siswaA->student->id,
            'attendance_date' => now()->toDateString(),
            'attendance_time' => '07:00:00',
            'status' => 'Hadir',
            'source' => 'Manual',
        ]);
        Attendance::create([
            'student_id' => $siswaB->student->id,
            'attendance_date' => now()->toDateString(),
            'attendance_time' => '07:00:00',
            'status' => 'Hadir',
            'source' => 'Manual',
        ]);

        // Tidak boleh memfilter absensi siswa lain.
        $this->actingAs($siswaA)
            ->getJson('/api/attendance?student_id='.$siswaB->student->id)
            ->assertForbidden();

        // Tanpa filter, hanya data miliknya yang muncul.
        $response = $this->actingAs($siswaA)->getJson('/api/attendance')->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($siswaA->student->id, $response->json('data.0.student_id'));

        // Detail absensi orang lain ditolak.
        $other = Attendance::where('student_id', $siswaB->student->id)->first();
        $this->actingAs($siswaA)->getJson('/api/attendance/'.$other->id)->assertForbidden();

        // Edit/hapus absensi hanya untuk admin.
        $this->actingAs($siswaA)->putJson('/api/attendance/'.$attendanceA->id, [
            'status' => 'Izin',
        ])->assertForbidden();
        $this->actingAs($siswaA)->deleteJson('/api/attendance/'.$attendanceA->id)->assertForbidden();
    }

    public function test_guru_bisa_membaca_tapi_tidak_mengubah_master_data(): void
    {
        $guru = $this->makeGuru('guru1');

        $this->actingAs($guru)->getJson('/api/students')->assertOk();
        $this->actingAs($guru)->getJson('/api/classes')->assertOk();
        $this->actingAs($guru)->getJson('/api/attendance')->assertOk();

        $this->actingAs($guru)->postJson('/api/students', [
            'nis' => '99998',
            'name' => 'Siswa Palsu',
        ])->assertForbidden();
        $this->actingAs($guru)->getJson('/api/teachers')->assertForbidden();
        $this->actingAs($guru)->getJson('/api/devices')->assertForbidden();
        $this->actingAs($guru)->getJson('/api/system-logs')->assertForbidden();
    }

    public function test_admin_bisa_membuat_siswa_dan_student_count_terupdate(): void
    {
        $admin = $this->makeAdmin();

        $class = SchoolClass::create([
            'name' => 'XI RPL 1',
            'major' => 'Rekayasa Perangkat Lunak',
            'student_count' => 0,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/students', [
            'class_id' => $class->id,
            'nis' => '10010',
            'name' => 'Siswa Baru',
            'status' => 'Aktif',
        ]);

        $response->assertCreated();
        $this->assertSame(1, $class->fresh()->student_count);

        // Hapus siswa -> student_count ikut turun.
        $this->actingAs($admin)->deleteJson('/api/students/'.$response->json('id'))->assertOk();
        $this->assertSame(0, $class->fresh()->student_count);
    }

    public function test_role_tidak_bisa_diubah_lewat_mass_assignment(): void
    {
        $siswa = $this->makeSiswa('siswa1', '10001');

        // Percobaan eskalasi role lewat update profil.
        $this->actingAs($siswa)->putJson('/api/me', [
            'name' => 'Siswa Berubah',
            'role' => 'admin',
        ])->assertOk();

        $this->assertSame('siswa', $siswa->fresh()->role);
        $this->assertSame('Siswa Berubah', $siswa->fresh()->name);
    }
}
