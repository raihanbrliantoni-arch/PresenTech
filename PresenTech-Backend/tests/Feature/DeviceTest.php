<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function createDevice(string $status = 'Online'): array
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'device_id' => 'ESP32-TEST-'.uniqid(),
            'name' => 'ESP32 Uji',
            'connection_type' => 'WiFi',
            'status' => $status,
        ]);

        $response->assertCreated();

        return [$admin, $response->json('id'), $response->json('device_key_plain')];
    }

    private function createOtherDevice(string $status = 'Online'): int
    {
        return Device::create([
            'device_id' => 'ESP32-LAIN-'.uniqid(),
            'name' => 'ESP32 Lain',
            'connection_type' => 'WiFi',
            'status' => $status,
        ])->id;
    }

    public function test_heartbeat_dengan_device_key_menandai_online_dan_mengembalikan_waktu_server(): void
    {
        [, $deviceId, $deviceKey] = $this->createDevice('Offline');
        Setting::current()->update(['late_time' => '06:45', 'home_time' => '13:30']);

        $response = $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/heartbeat', [
                'ip_address' => '192.168.1.50',
                'firmware_version' => '1.0.0',
            ]);

        $response->assertOk()
            ->assertJsonPath('device.id', $deviceId)
            ->assertJsonPath('device.status', 'Online')
            ->assertJsonPath('device.firmware_version', '1.0.0')
            ->assertJsonPath('late_time', '06:45')
            ->assertJsonPath('home_time', '13:30')
            ->assertJsonPath('restart_requested', false)
            ->assertJsonStructure(['server_time', 'server_date', 'entry_time', 'timezone', 'check_out_active']);

        $this->assertDatabaseHas('devices', [
            'id' => $deviceId,
            'status' => 'Online',
            'ip_address' => '192.168.1.50',
            'firmware_version' => '1.0.0',
        ]);
        $this->assertNotNull(Device::find($deviceId)->last_seen_at);
    }

    public function test_heartbeat_tanpa_device_key_ditolak_401(): void
    {
        $this->postJson('/api/device/heartbeat', ['firmware_version' => '1.0.0'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Device key tidak valid.');
    }

    public function test_heartbeat_dengan_device_key_salah_ditolak_401(): void
    {
        $this->withHeader('X-Device-Key', 'ptd_key_palsu')
            ->postJson('/api/device/heartbeat', ['firmware_version' => '1.0.0'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Device key tidak valid.');
    }

    public function test_heartbeat_perangkat_maintenance_tetap_dalam_mode_maintenance(): void
    {
        [, $deviceId, $deviceKey] = $this->createDevice('Maintenance');

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/heartbeat')
            ->assertOk()
            ->assertJsonPath('device.status', 'Maintenance');

        $this->assertDatabaseHas('devices', ['id' => $deviceId, 'status' => 'Maintenance']);
    }

    public function test_perintah_restart_dilaporkan_pada_heartbeat_dan_dihapus_setelah_ack(): void
    {
        [$admin, $deviceId, $deviceKey] = $this->createDevice('Online');

        $this->actingAs($admin)
            ->postJson("/api/devices/{$deviceId}/restart")
            ->assertOk();

        $this->assertNotNull(Device::find($deviceId)->restart_requested_at);

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/heartbeat')
            ->assertOk()
            ->assertJsonPath('restart_requested', true);

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/restart-ack')
            ->assertOk();

        $this->assertNull(Device::find($deviceId)->restart_requested_at);

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/heartbeat')
            ->assertOk()
            ->assertJsonPath('restart_requested', false);
    }

    public function test_ack_restart_tanpa_device_key_ditolak_401(): void
    {
        $this->postJson('/api/device/restart-ack')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Device key tidak valid.');
    }

    public function test_command_menandai_perangkat_lama_offline_tanpa_mengubah_yang_baru_dan_maintenance(): void
    {
        $this->travelTo('2026-10-25 08:00:00');

        $stale = Device::create([
            'device_id' => 'ESP32-STALE',
            'name' => 'ESP32 Lama',
            'status' => 'Online',
            'last_seen_at' => now()->subMinutes(2),
        ]);
        $fresh = Device::create([
            'device_id' => 'ESP32-FRESH',
            'name' => 'ESP32 Baru',
            'status' => 'Online',
            'last_seen_at' => now()->subSeconds(30),
        ]);
        $maintenance = Device::create([
            'device_id' => 'ESP32-MAINTENANCE',
            'name' => 'ESP32 Maintenance',
            'status' => 'Maintenance',
            'last_seen_at' => now()->subMinutes(10),
        ]);

        $this->artisan('devices:mark-offline')->assertSuccessful();

        $this->assertSame('Offline', $stale->fresh()->status);
        $this->assertSame('Online', $fresh->fresh()->status);
        $this->assertSame('Maintenance', $maintenance->fresh()->status);
    }

    public function test_rate_limit_perangkat_dikunci_per_device_key_dan_fallback_ke_ip(): void
    {
        $limiter = RateLimiter::limiter('device');

        $requestWithKey = fn (string $key) => tap(
            Request::create('/api/device/heartbeat', 'POST', server: ['REMOTE_ADDR' => '192.168.1.50']),
            fn (Request $request) => $request->headers->set('X-Device-Key', $key),
        );

        $withoutKey = Request::create('/api/device/heartbeat', 'POST', server: ['REMOTE_ADDR' => '192.168.1.50']);

        $firstDevice = $limiter($requestWithKey('ptd_perangkat_satu'));
        $secondDevice = $limiter($requestWithKey('ptd_perangkat_dua'));
        $anonymous = $limiter($withoutKey);

        $this->assertNotSame($firstDevice->key, $secondDevice->key);
        $this->assertSame(60, $firstDevice->maxAttempts);
        $this->assertSame('ip:192.168.1.50', $anonymous->key);
        $this->assertSame(120, $anonymous->maxAttempts);
    }

    public function test_heartbeat_lewat_bindings_perangkat_tanpa_device_key_ditolak(): void
    {
        [$admin, $deviceId] = $this->createDevice('Offline');

        // Dulu sesi Admin cukup untuk memicu heartbeat perangkat mana pun.
        $this->actingAs($admin)
            ->postJson("/api/devices/{$deviceId}/heartbeat")
            ->assertUnauthorized();

        $this->assertNull(Device::find($deviceId)->last_seen_at);
    }

    public function test_heartbeat_lewat_bindings_perangkat_lain_ditolak(): void
    {
        [, $deviceId, $deviceKey] = $this->createDevice('Online');
        $otherId = $this->createOtherDevice('Offline');

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson("/api/devices/{$otherId}/heartbeat")
            ->assertForbidden();

        $this->assertNull(Device::find($otherId)->last_seen_at);
    }

    public function test_heartbeat_lewat_bindings_perangkat_sendiri_diterima(): void
    {
        [, $deviceId, $deviceKey] = $this->createDevice('Offline');

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson("/api/devices/{$deviceId}/heartbeat")
            ->assertOk()
            ->assertJsonPath('device.id', $deviceId);

        $this->assertNotNull(Device::find($deviceId)->last_seen_at);
    }

    public function test_heartbeat_mengirim_teks_layar_yang_sudah_dipendekkan(): void
    {
        [, $deviceId, $deviceKey] = $this->createDevice('Online');

        Device::whereKey($deviceId)->update([
            'name' => 'Perangkat Absensi Gerbang Utama Sekolah',
            'school_label' => 'SMK Nusantara Kota Bandung',
        ]);

        $response = $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/heartbeat')
            ->assertOk()
            ->assertJsonPath('capabilities.0', 'display_text');

        $display = $response->json('display');

        // Nama dipotong di batas kata (bukan di tengah kata) dan memakai elipsis.
        $this->assertLessThanOrEqual(20, mb_strlen($display['device_name']));
        $this->assertLessThanOrEqual(16, mb_strlen($display['device_name_short']));
        $this->assertStringEndsWith('…', $display['device_name']);
        $this->assertStringStartsWith('Perangkat', $display['device_name']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $display['theme_color']);
        $this->assertNotSame('', $display['school_label']);
        $this->assertContains($display['state'], ['masuk', 'terlambat', 'pulang']);
    }

    public function test_boot_id_baru_menghapus_flag_restart_dan_menandai_terkonfirmasi(): void
    {
        [, $deviceId, $deviceKey] = $this->createDevice('Online');

        // Perangkat mengirim boot_id pertama (restart belum diminta).
        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/heartbeat', ['boot_id' => 'boot-aaa111'])
            ->assertOk()
            ->assertJsonPath('boot_acknowledged', false);

        $this->travel(2)->minutes();

        // Pakai query builder, bukan Device::whereKey()->update(): update lewat
        // Eloquent memuat model ke cache dan request berikutnya membaca nilai
        // lama (flag restart terlihat belum diset).
        DB::table('devices')->where('id', $deviceId)->update(['restart_requested_at' => now()]);

        $this->assertNotNull(Device::findOrFail($deviceId)->restart_requested_at, 'flag restart harus tersimpan');

        // Perangkat menyala ulang sendiri tanpa ack: boot_id berubah -> flag
        // restart harus bersih supaya alat tidak diminta restart terus.
        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/heartbeat', ['boot_id' => 'boot-bbb222'])
            ->assertOk()
            ->assertJsonPath('restart_requested', false)
            ->assertJsonPath('boot_acknowledged', true);

        $device = Device::find($deviceId);
        $this->assertSame('boot-bbb222', $device->boot_id);
        $this->assertNotNull($device->booted_at);
        $this->assertNull($device->restart_requested_at);

        // Heartbeat berikutnya dengan boot_id sama tidak mengulang ack.
        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/heartbeat', ['boot_id' => 'boot-bbb222'])
            ->assertOk()
            ->assertJsonPath('boot_acknowledged', false);
    }

    public function test_pratinjau_layar_menampilkan_nama_pendek_dan_warna_tema(): void
    {
        [$admin, $deviceId] = $this->createDevice('Online');

        $this->actingAs($admin)
            ->putJson("/api/devices/{$deviceId}", [
                'name' => 'Rekayasa Perangkat Lunak Lab Komputer Satu',
                'display_name' => 'Lab RPL 1',
                'theme_color' => '#1F6FEB',
            ])
            ->assertOk()
            ->assertJsonPath('theme_color', '#1f6feb');

        $this->actingAs($admin)
            ->getJson("/api/devices/{$deviceId}/preview")
            ->assertOk()
            ->assertJsonPath('display.device_name', 'Lab RPL 1')
            ->assertJsonPath('display.theme_color', '#1f6feb')
            ->assertJsonStructure(['display' => ['clock', 'date_label', 'greeting', 'state'], 'window' => ['server_time']]);
    }

    public function test_warna_tema_tidak_valid_ditolak(): void
    {
        [$admin, $deviceId] = $this->createDevice('Online');

        $this->actingAs($admin)
            ->putJson("/api/devices/{$deviceId}", ['theme_color' => 'merah'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('theme_color');
    }

    public function test_log_perangkat_tidak_membocorkan_metadata_di_respons_dashboard(): void
    {
        [, $deviceId] = $this->createDevice('Online');

        Device::find($deviceId)->systemLogs()->create([
            'type' => 'device',
            'action' => 'device.ota_requested',
            'message' => 'OTA diminta',
            'metadata' => ['device_id' => $deviceId, 'file' => 'firmware-1.0.0.bin'],
        ]);

        $payload = json_encode(Device::with('systemLogs')->findOrFail($deviceId)->toArray());

        $this->assertStringContainsString('device.ota_requested', $payload);
        $this->assertStringNotContainsString('metadata', $payload);
        $this->assertStringNotContainsString('firmware-1.0.0.bin', $payload);
    }
}
