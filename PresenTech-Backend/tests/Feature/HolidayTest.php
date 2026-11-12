<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class HolidayTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_admin_bisa_menambah_dan_melihat_hari_libur(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/holidays', [
                'date' => '2026-12-25',
                'name' => 'Hari Raya Natal',
                'type' => 'nasional',
            ])
            ->assertCreated()
            ->assertJsonPath('date', '2026-12-25')
            ->assertJsonPath('name', 'Hari Raya Natal')
            ->assertJsonPath('type_label', 'Libur Nasional');

        $this->assertDatabaseHas('holidays', [
            'date' => '2026-12-25',
            'name' => 'Hari Raya Natal',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/holidays?year=2026')
            ->assertOk()
            ->assertJsonPath('data.0.date', '2026-12-25');
    }

    public function test_tanggal_libur_tidak_boleh_duplikat(): void
    {
        $admin = $this->makeAdmin();
        Holiday::create(['date' => '2026-12-25', 'name' => 'Natal', 'type' => 'nasional']);

        $this->actingAs($admin)
            ->postJson('/api/holidays', [
                'date' => '2026-12-25',
                'name' => 'Libur lagi',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_guru_dan_siswa_tidak_boleh_mengubah_hari_libur(): void
    {
        $holiday = Holiday::create(['date' => '2026-12-25', 'name' => 'Natal', 'type' => 'nasional']);

        $this->actingAs($this->makeGuru('gurulibur'))
            ->postJson('/api/holidays', [
                'date' => '2026-12-26',
                'name' => 'Cuti bersama',
            ])
            ->assertForbidden();

        $this->actingAs($this->makeSiswa('siswalibur', '30001'))
            ->deleteJson("/api/holidays/{$holiday->id}")
            ->assertForbidden();
    }

    public function test_status_hari_libur_hari_ini_dapat_dibaca_semua_role(): void
    {
        Holiday::create([
            'date' => now()->toDateString(),
            'name' => 'Libur Sekolah',
            'type' => 'sekolah',
        ]);

        $this->actingAs($this->makeSiswa('siswatoday', '30002'))
            ->getJson('/api/holidays/today')
            ->assertOk()
            ->assertJsonPath('is_holiday', true)
            ->assertJsonPath('holiday.name', 'Libur Sekolah')
            ->assertJsonPath('holiday.type_label', 'Libur Sekolah');
    }

    public function test_perintah_pembersih_hanya_menghapus_alpa_otomatis_pada_hari_libur(): void
    {
        $class = SchoolClass::create(['name' => 'XII RPL 1', 'major' => 'RPL', 'student_count' => 0]);
        $siswa = $this->makeSiswa('pembersihlibur', '30003');
        $siswa->student->update(['class_id' => $class->id]);

        Holiday::create(['date' => '2026-12-25', 'name' => 'Natal', 'type' => 'nasional']);

        $autoOnHoliday = Attendance::create([
            'student_id' => $siswa->student->id,
            'attendance_date' => '2026-12-25',
            'attendance_time' => '07:15:00',
            'status' => 'Alpa',
            'auto_alpa' => true,
            'source' => 'Manual',
        ]);

        $manualOnHoliday = Attendance::create([
            'student_id' => $siswa->student->id,
            'attendance_date' => '2026-12-25',
            'attendance_time' => '08:00:00',
            'status' => 'Alpa',
            'auto_alpa' => false,
            'source' => 'Manual',
        ]);

        $autoOnNormalDay = Attendance::create([
            'student_id' => $siswa->student->id,
            'attendance_date' => '2026-12-24',
            'attendance_time' => '07:15:00',
            'status' => 'Alpa',
            'auto_alpa' => true,
            'source' => 'Manual',
        ]);

        $this->artisan('attendance:clear-holiday-alpa')->assertSuccessful();

        $this->assertDatabaseMissing('attendances', ['id' => $autoOnHoliday->id]);
        $this->assertDatabaseHas('attendances', ['id' => $manualOnHoliday->id]);
        $this->assertDatabaseHas('attendances', ['id' => $autoOnNormalDay->id]);
    }

    public function test_hari_libur_dapat_diubah_dan_dihapus(): void
    {
        $admin = $this->makeAdmin();
        $holiday = Holiday::create(['date' => '2026-12-24', 'name' => 'Cuti bersama', 'type' => 'sekolah']);

        $this->actingAs($admin)
            ->putJson("/api/holidays/{$holiday->id}", [
                'date' => '2026-12-24',
                'name' => 'Cuti bersama Natal',
                'type' => 'nasional',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Cuti bersama Natal')
            ->assertJsonPath('type', 'nasional');

        $this->actingAs($admin)
            ->deleteJson("/api/holidays/{$holiday->id}")
            ->assertOk();

        $this->assertModelMissing($holiday);
    }

    public function test_pembersih_tidak_menyentuh_absensi_biasa(): void
    {
        // Pengaman: perintah tidak menyentuh absensi yang bukan auto_alpa.
        $siswa = $this->makeSiswa('tanpakelaslibur', '30004');
        Holiday::create(['date' => '2026-12-25', 'name' => 'Natal', 'type' => 'nasional']);

        Attendance::create([
            'student_id' => $siswa->student->id,
            'attendance_date' => '2026-12-25',
            'attendance_time' => '07:00:00',
            'status' => 'Hadir',
            'source' => 'RFID',
        ]);

        $this->artisan('attendance:clear-holiday-alpa', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, Attendance::whereDate('attendance_date', '2026-12-25')->count());
        $this->assertSame(0, Student::where('nis', '30004')->whereHas('attendances', fn ($query) => $query->where('status', 'Alpa'))->count());
    }
}
