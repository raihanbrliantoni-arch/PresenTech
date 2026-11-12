<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_admin_bisa_melihat_kesehatan_sistem(): void
    {
        Device::create([
            'device_id' => 'ESP32-HEALTH-1',
            'name' => 'Alat Pintu',
            'status' => 'Online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/system/health')
            ->assertOk()
            ->assertJsonPath('devices.total', 1)
            ->assertJsonPath('devices.online', 1)
            ->assertJsonPath('scheduler.stale', true)
            ->assertJsonStructure([
                'server_time',
                'scheduler' => ['last_run_at', 'stale'],
                'devices' => ['total', 'online', 'offline', 'maintenance', 'stale', 'restart_pending'],
                'attendance_today' => ['total', 'hadir', 'terlambat', 'izin', 'sakit', 'alpa', 'auto_alpa'],
                'unverified_taps_7d',
                'logs' => ['total', 'last_24h'],
                'backups' => ['retention_days', 'count', 'latest'],
            ]);
    }

    public function test_scheduler_dianggap_sehat_setelah_heartbeat(): void
    {
        $this->artisan('system:heartbeat')->assertSuccessful();

        $this->assertNotNull(Cache::get('presentech.scheduler.last_run_at'));

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/system/health')
            ->assertOk()
            ->assertJsonPath('scheduler.stale', false)
            ->assertJsonPath('scheduler.last_run_at', now()->toIso8601String());
    }

    public function test_ringkasan_absensi_hari_ini_dihitung(): void
    {
        $siswa = $this->makeSiswa('sehatabsensi', '40001');

        Attendance::create([
            'student_id' => $siswa->student->id,
            'attendance_date' => now()->toDateString(),
            'attendance_time' => '07:00:00',
            'status' => 'Hadir',
            'source' => 'Manual',
        ]);

        Attendance::create([
            'student_id' => $siswa->student->id,
            'attendance_date' => now()->subDay()->toDateString(),
            'attendance_time' => '07:00:00',
            'status' => 'Alpa',
            'source' => 'Manual',
            'auto_alpa' => true,
        ]);

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/system/health')
            ->assertOk()
            ->assertJsonPath('attendance_today.total', 1)
            ->assertJsonPath('attendance_today.hadir', 1)
            ->assertJsonPath('attendance_today.auto_alpa', 0);
    }

    public function test_guru_dan_siswa_tidak_boleh_melihat_kesehatan_sistem(): void
    {
        $this->actingAs($this->makeGuru('guruhealth'))
            ->getJson('/api/system/health')
            ->assertForbidden();

        $this->actingAs($this->makeSiswa('siswahealth', '40002'))
            ->getJson('/api/system/health')
            ->assertForbidden();
    }

    public function test_perangkat_online_yang_basi_dihitung_terpisah(): void
    {
        Device::create([
            'device_id' => 'ESP32-HEALTH-2',
            'name' => 'Alat Basi',
            'status' => 'Online',
            'last_seen_at' => now()->subMinutes(15),
        ]);

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/system/health')
            ->assertOk()
            ->assertJsonPath('devices.stale', 1);
    }
}
