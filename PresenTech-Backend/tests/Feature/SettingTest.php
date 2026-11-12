<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Setting;
use App\Models\SystemLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_semua_role_bisa_membaca_pengaturan_tanpa_detail_mqtt(): void
    {
        $siswa = $this->makeSiswa('siswa1', '10001');

        $this->actingAs($siswa)
            ->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('settings.late_time', '07:15')
            ->assertJsonMissingPath('settings.mqtt_broker')
            ->assertJsonMissingPath('settings.mqtt_topic');
    }

    public function test_hanya_admin_bisa_mengubah_pengaturan(): void
    {
        $siswa = $this->makeSiswa('siswa1', '10001');
        $admin = $this->makeAdmin();

        $this->actingAs($siswa)
            ->putJson('/api/settings', ['late_time' => '07:30'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->putJson('/api/settings', ['late_time' => '07:30', 'semester' => 'Genap'])
            ->assertOk()
            ->assertJsonPath('settings.late_time', '07:30');

        $this->assertSame('07:30', Setting::current()->late_time);
    }

    public function test_pengaturan_mqtt_tersimpan_di_server(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->putJson('/api/settings', [
                'mqtt_broker' => 'mqtt://broker.test',
                'mqtt_port' => '1884',
                'mqtt_topic' => 'presentech/test',
            ])
            ->assertOk()
            ->assertJsonPath('settings.mqtt_broker', 'mqtt://broker.test')
            ->assertJsonPath('settings.mqtt_port', '1884')
            ->assertJsonPath('settings.mqtt_topic', 'presentech/test');

        $this->actingAs($admin)
            ->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('settings.mqtt_broker', 'mqtt://broker.test');
    }

    public function test_pengaturan_tidak_valid_ditolak(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->putJson('/api/settings', ['late_time' => 'jam-sebelas', 'semester' => 'Tiga'])
            ->assertUnprocessable();
    }

    public function test_password_mqtt_tidak_pernah_dikirim_ke_client(): void
    {
        Setting::current()->update(['mqtt_password' => 'rahasia-broker']);
        $siswa = $this->makeSiswa('siswa1', '10001');

        $this->actingAs($siswa)
            ->getJson('/api/settings')
            ->assertOk()
            ->assertJsonMissingPath('settings.mqtt_password')
            ->assertJsonPath('settings.mqtt_password_configured', true);
    }

    public function test_menyimpan_pengaturan_lain_tidak_menghapus_password_mqtt(): void
    {
        Setting::current()->update(['mqtt_password' => 'rahasia-broker']);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->putJson('/api/settings', ['late_time' => '07:45'])
            ->assertOk()
            ->assertJsonMissingPath('settings.mqtt_password');

        $this->assertSame('rahasia-broker', Setting::current()->mqtt_password);
    }

    public function test_perubahan_jam_absensi_meminta_perangkat_restart(): void
    {
        $admin = $this->makeAdmin();
        $device = Device::create([
            'device_id' => 'DEV-SYNC-1',
            'name' => 'Alat Sinkronisasi',
            'status' => 'Offline',
        ]);
        $device->forceFill(['device_key' => hash('sha256', 'kunci-alat')])->save();

        $this->actingAs($admin)
            ->putJson('/api/settings', ['entry_time' => '06:45'])
            ->assertOk()
            ->assertJsonPath('device_sync', 'restart_requested')
            ->assertJsonPath('devices.0.name', 'Alat Sinkronisasi')
            ->assertJsonPath('devices.0.restart_pending', true);

        $this->assertNotNull($device->fresh()->restart_requested_at);
    }

    public function test_revisi_jadwal_naik_saat_jam_berubah_dan_dikirim_ke_perangkat(): void
    {
        $admin = $this->makeAdmin();
        $device = Device::create([
            'device_id' => 'DEV-REVISI-1',
            'name' => 'Alat Revisi Jadwal',
            'status' => 'Offline',
        ]);
        $device->forceFill(['device_key' => hash('sha256', 'kunci-revisi')])->save();

        $revisiAwal = (int) Setting::current()->fresh()->schedule_revision;

        $this->actingAs($admin)
            ->putJson('/api/settings', ['entry_time' => '06:45'])
            ->assertOk();

        $this->assertSame($revisiAwal + 1, (int) Setting::current()->schedule_revision);

        $this->withHeader('X-Device-Key', 'kunci-revisi')
            ->postJson('/api/device/heartbeat')
            ->assertOk()
            ->assertJsonPath('schedule_revision', $revisiAwal + 1);

        // Menyimpan jam yang sama tidak boleh menaikkan revisi, supaya alat
        // tidak berbunyi/restart tanpa alasan.
        $this->actingAs($admin)
            ->putJson('/api/settings', ['entry_time' => '06:45'])
            ->assertOk();

        $this->assertSame($revisiAwal + 1, (int) Setting::current()->schedule_revision);
    }

    public function test_simpan_tanpa_perubahan_jam_tidak_merestart_perangkat(): void
    {
        $admin = $this->makeAdmin();
        $device = Device::create([
            'device_id' => 'DEV-SYNC-2',
            'name' => 'Alat Sinkronisasi',
            'status' => 'Offline',
        ]);
        $device->forceFill(['device_key' => hash('sha256', 'kunci-alat-2')])->save();

        $this->actingAs($admin)
            ->putJson('/api/settings', ['semester' => 'Genap'])
            ->assertOk()
            ->assertJsonPath('device_sync', 'heartbeat')
            ->assertJsonPath('devices', []);

        $this->assertNull($device->fresh()->restart_requested_at);
    }

    public function test_password_mqtt_di_audit_log_disenitasi(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->putJson('/api/settings', [
                'mqtt_password' => 'rahasia-baru',
                'mqtt_broker' => 'mqtt://broker.test',
            ])
            ->assertOk()
            ->assertJsonMissingPath('settings.mqtt_password');

        $log = SystemLog::where('action', 'settings.updated')->latest()->first();

        $this->assertNotNull($log);
        $this->assertArrayNotHasKey('mqtt_password', $log->metadata);
    }

    public function test_jam_absensi_bisa_diatur_berbeda_per_hari(): void
    {
        $admin = $this->makeAdmin();
        $device = Device::create([
            'device_id' => 'DEV-HARIAN-1',
            'name' => 'Alat Jadwal Harian',
            'status' => 'Offline',
        ]);
        $device->forceFill(['device_key' => hash('sha256', 'kunci-harian')])->save();

        $this->actingAs($admin)
            ->putJson('/api/settings', [
                'day_times' => [
                    'monday' => [
                        'entry_time' => '06:30',
                        'late_time' => '06:45',
                        'home_time' => '12:30',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('settings.day_times.monday.entry_time', '06:30')
            ->assertJsonPath('settings.day_times.monday.late_time', '06:45')
            ->assertJsonPath('settings.day_times.monday.home_time', '12:30')
            ->assertJsonPath('device_sync', 'restart_requested');

        $this->assertNotNull($device->fresh()->restart_requested_at);
    }

    public function test_hari_tanpa_jam_khusus_memakai_jam_umum(): void
    {
        $settings = Setting::current();
        $settings->update([
            'entry_time' => '07:00',
            'late_time' => '07:15',
            'home_time' => '14:00',
            'day_times' => [
                'monday' => ['entry_time' => '06:30', 'late_time' => null, 'home_time' => null],
            ],
        ]);

        $monday = $settings->fresh()->timesForDate('2026-11-02');
        $this->assertSame('06:30', $monday['entry_time']);
        $this->assertSame('07:15', $monday['late_time']);
        $this->assertSame('14:00', $monday['home_time']);

        $tuesday = $settings->fresh()->timesForDate('2026-11-03');
        $this->assertSame('07:00', $tuesday['entry_time']);
        $this->assertSame('07:15', $tuesday['late_time']);
        $this->assertSame('14:00', $tuesday['home_time']);
    }

    public function test_perangkat_menerima_jam_hari_ini_dari_pengaturan_per_hari(): void
    {
        // Kunci tanggal ke hari kerja agar hasilnya tidak bergantung hari
        // saat test dijalankan.
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));

        $device = Device::create([
            'device_id' => 'DEV-HARIAN-2',
            'name' => 'Alat Heartbeat Harian',
            'status' => 'Offline',
        ]);
        $device->forceFill(['device_key' => hash('sha256', 'kunci-harian-2')])->save();

        Setting::current()->update([
            'day_times' => [
                'monday' => [
                    'entry_time' => '06:30',
                    'late_time' => '06:45',
                    'home_time' => '12:30',
                ],
            ],
        ]);

        $this->withHeader('X-Device-Key', 'kunci-harian-2')
            ->postJson('/api/device/heartbeat')
            ->assertOk()
            ->assertJsonPath('entry_time', '06:30')
            ->assertJsonPath('late_time', '06:45')
            ->assertJsonPath('home_time', '12:30');
    }

    public function test_jam_per_hari_tidak_valid_ditolak(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->putJson('/api/settings', [
                'day_times' => ['senin' => ['entry_time' => '07:00']],
            ])
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->putJson('/api/settings', [
                'day_times' => ['monday' => ['entry_time' => 'jam-tujuh']],
            ])
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->putJson('/api/settings', [
                'day_times' => ['saturday' => ['entry_time' => '07:00']],
            ])
            ->assertUnprocessable();
    }
}
