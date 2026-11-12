<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\RfidCard;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class MarkAttendanceAlpaTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function makeClass(): SchoolClass
    {
        return SchoolClass::create([
            'name' => 'X RPL 1',
            'major' => 'RPL',
            'student_count' => 0,
        ]);
    }

    public function test_siswa_tanpa_catatan_ditandai_alpa_dan_tidak_duplikat(): void
    {
        $class = $this->makeClass();

        $hadir = $this->makeSiswa('hadiralpa', '20001');
        $hadir->student->update(['class_id' => $class->id]);
        $alpa = $this->makeSiswa('alpaotomatis', '20002');
        $alpa->student->update(['class_id' => $class->id]);

        Attendance::create([
            'student_id' => $hadir->student->id,
            'attendance_date' => '2026-10-28',
            'attendance_time' => '07:00:00',
            'status' => 'Hadir',
            'source' => 'RFID',
        ]);

        $this->artisan('attendance:mark-alpa', ['--date' => '2026-10-28'])->assertSuccessful();

        $this->assertSame(1, Attendance::where('status', 'Alpa')->whereDate('attendance_date', '2026-10-28')->count());

        // Dijalankan ulang: tidak boleh menambah record baru.
        $this->artisan('attendance:mark-alpa', ['--date' => '2026-10-28'])->assertSuccessful();

        $this->assertSame(1, Attendance::where('status', 'Alpa')->whereDate('attendance_date', '2026-10-28')->count());
        $this->assertSame(2, Attendance::whereDate('attendance_date', '2026-10-28')->count());
    }

    public function test_akhir_pekan_dilewati_kecuali_diminta(): void
    {
        $class = $this->makeClass();
        $student = $this->makeSiswa('akhirpekan', '20003');
        $student->student->update(['class_id' => $class->id]);

        // 2026-10-31 adalah Sabtu.
        $this->artisan('attendance:mark-alpa', ['--date' => '2026-10-31'])->assertSuccessful();
        $this->assertSame(0, Attendance::whereDate('attendance_date', '2026-10-31')->count());

        $this->artisan('attendance:mark-alpa', ['--date' => '2026-10-31', '--include-weekend' => true])->assertSuccessful();
        $this->assertSame(1, Attendance::whereDate('attendance_date', '2026-10-31')->count());
    }

    public function test_hari_libur_dilewati_kecuali_diminta(): void
    {
        $class = $this->makeClass();
        $student = $this->makeSiswa('liburnasional', '20006');
        $student->student->update(['class_id' => $class->id]);

        Holiday::create([
            'date' => '2026-12-25',
            'name' => 'Hari Raya Natal',
            'type' => 'nasional',
        ]);

        $this->artisan('attendance:mark-alpa', ['--date' => '2026-12-25'])->assertSuccessful();
        $this->assertSame(0, Attendance::whereDate('attendance_date', '2026-12-25')->count());

        $this->artisan('attendance:mark-alpa', [
            '--date' => '2026-12-25',
            '--include-holiday' => true,
        ])->assertSuccessful();
        $this->assertSame(1, Attendance::whereDate('attendance_date', '2026-12-25')->count());
    }

    public function test_siswa_nonaktif_tidak_ditandai(): void
    {
        $class = $this->makeClass();
        $student = $this->makeSiswa('nonaktifalpa', '20004');
        $student->student->update(['class_id' => $class->id, 'status' => 'Nonaktif']);

        $this->artisan('attendance:mark-alpa', ['--date' => '2026-10-28'])->assertSuccessful();

        $this->assertSame(0, Attendance::whereDate('attendance_date', '2026-10-28')->count());
        $this->assertSame(0, Student::where('nis', '20004')->whereHas('attendances')->count());
    }

    public function test_tap_setelah_alpa_otomatis_memperbarui_baris_bukan_duplikat(): void
    {
        $class = $this->makeClass();
        $student = $this->makeSiswa('tapsetelah', '20005');
        $student->student->update(['class_id' => $class->id]);

        RfidCard::create([
            'student_id' => $student->student->id,
            'uid' => 'A1B2C3D4',
            'label' => 'Uji',
            'status' => 'Aktif',
            'registered_at' => now(),
        ]);

        $admin = $this->makeAdmin();
        $device = $this->actingAs($admin)->postJson('/api/devices', [
            'device_id' => 'ESP32-ALPA-1',
            'name' => 'ESP32 Alpa',
            'connection_type' => 'WiFi',
            'status' => 'Online',
        ])->json();

        $this->artisan('attendance:mark-alpa', ['--force' => true, '--include-weekend' => true])->assertSuccessful();

        $this->assertDatabaseHas('attendances', [
            'student_id' => $student->student->id,
            'status' => 'Alpa',
            'auto_alpa' => true,
        ]);

        $this->withHeader('X-Device-Key', $device['device_key_plain'])
            ->postJson('/api/attendance', ['uid' => 'A1B2C3D4'])
            ->assertCreated()
            ->assertJsonPath('attendance.auto_alpa', false);

        $this->assertSame(1, Attendance::where('student_id', $student->student->id)->count());
        $this->assertContains(
            Attendance::where('student_id', $student->student->id)->first()->status,
            ['Hadir', 'Terlambat'],
        );
    }
}
