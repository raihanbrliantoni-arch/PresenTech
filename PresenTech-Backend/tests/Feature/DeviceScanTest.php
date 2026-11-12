<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DeviceScanTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** @var array<int, string> */
    private array $deviceKeys = [];

    private function createDevice(string $status = 'Online'): array
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'device_id' => 'ESP32-SCAN-'.uniqid(),
            'name' => 'ESP32 Scan',
            'connection_type' => 'WiFi',
            'status' => $status,
        ]);

        $response->assertCreated();

        $deviceId = $response->json('id');
        $this->deviceKeys[$deviceId] = $response->json('device_key_plain');

        return [$admin, $deviceId, $this->deviceKeys[$deviceId]];
    }

    public function test_admin_meminta_scan_dan_heartbeat_melaporkan_permintaan(): void
    {
        [$admin, $deviceId] = $this->createDevice('Online');

        $this->actingAs($admin)
            ->postJson("/api/devices/{$deviceId}/scan")
            ->assertOk()
            ->assertJsonPath('status', 'waiting')
            ->assertJsonStructure(['expires_at']);

        $this->assertNotNull(Device::find($deviceId)->scan_requested_at);

        $this->withHeader('X-Device-Key', $this->deviceKey($deviceId))
            ->postJson('/api/device/heartbeat')
            ->assertOk()
            ->assertJsonPath('scan_requested', true);
    }

    public function test_heartbeat_tanpa_permintaan_scan_mengembalikan_false(): void
    {
        [, $deviceId] = $this->createDevice('Online');

        $this->withHeader('X-Device-Key', $this->deviceKey($deviceId))
            ->postJson('/api/device/heartbeat')
            ->assertOk()
            ->assertJsonPath('scan_requested', false)
            ->assertJsonPath('scan_expires_in', 0);
    }

    public function test_hasil_scan_menyimpan_uid_ternormalisasi_dan_mengakhiri_permintaan(): void
    {
        [$admin, $deviceId] = $this->createDevice('Online');
        $deviceKey = $this->deviceKey($deviceId);

        $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/scan")->assertOk();

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/scan-result', ['uid' => '039d 7641'])
            ->assertOk()
            ->assertJsonPath('uid', '039D7641');

        $device = Device::find($deviceId);
        $this->assertSame('039D7641', $device->scanned_uid);
        $this->assertNull($device->scan_requested_at);
        $this->assertNotNull($device->scanned_at);

        $this->actingAs($admin)
            ->getJson("/api/devices/{$deviceId}/scan")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('uid', '039D7641');

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/heartbeat')
            ->assertOk()
            ->assertJsonPath('scan_requested', false);
    }

    public function test_hasil_scan_tanpa_permintaan_ditolak_409(): void
    {
        [, $deviceId] = $this->createDevice('Online');

        $this->withHeader('X-Device-Key', $this->deviceKey($deviceId))
            ->postJson('/api/device/scan-result', ['uid' => '039D7641'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Tidak ada permintaan baca UID untuk perangkat ini.');
    }

    public function test_hasil_scan_tanpa_device_key_ditolak_401(): void
    {
        $this->postJson('/api/device/scan-result', ['uid' => '039D7641'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Device key tidak valid.');
    }

    public function test_hasil_scan_dengan_uid_kosong_ditolak_422(): void
    {
        [$admin, $deviceId] = $this->createDevice('Online');
        $deviceKey = $this->deviceKey($deviceId);

        $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/scan")->assertOk();

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/scan-result', ['uid' => ''])
            ->assertStatus(422);
    }

    public function test_permintaan_scan_kedaluwarsa_tidak_menerima_uid(): void
    {
        [$admin, $deviceId] = $this->createDevice('Online');
        $deviceKey = $this->deviceKey($deviceId);

        $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/scan")->assertOk();

        $this->travel(61)->seconds();

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/scan-result', ['uid' => '039D7641'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Permintaan baca UID sudah kedaluwarsa.');

        $this->assertNull(Device::find($deviceId)->scan_requested_at);
    }

    public function test_status_scan_kedaluwarsa_menjadi_idle(): void
    {
        [$admin, $deviceId] = $this->createDevice('Online');

        $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/scan")->assertOk();

        $this->travel(61)->seconds();

        $this->actingAs($admin)
            ->getJson("/api/devices/{$deviceId}/scan")
            ->assertOk()
            ->assertJsonPath('status', 'idle');

        $this->assertNull(Device::find($deviceId)->scan_requested_at);
    }

    public function test_batal_scan_menghapus_permintaan_dan_hasil(): void
    {
        [$admin, $deviceId] = $this->createDevice('Online');
        $deviceKey = $this->deviceKey($deviceId);

        $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/scan")->assertOk();
        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/scan-result', ['uid' => '039D7641'])
            ->assertOk();

        $this->actingAs($admin)
            ->deleteJson("/api/devices/{$deviceId}/scan")
            ->assertOk()
            ->assertJsonPath('status', 'idle');

        $device = Device::find($deviceId);
        $this->assertNull($device->scan_requested_at);
        $this->assertNull($device->scanned_uid);
        $this->assertNull($device->scanned_at);
    }

    public function test_perangkat_offline_tidak_bisa_diminta_scan(): void
    {
        [$admin, $deviceId] = $this->createDevice('Offline');

        $this->actingAs($admin)
            ->postJson("/api/devices/{$deviceId}/scan")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Perangkat sedang offline. Nyalakan perangkat terlebih dahulu.');
    }

    public function test_perangkat_maintenance_tidak_bisa_diminta_scan(): void
    {
        [$admin, $deviceId] = $this->createDevice('Maintenance');

        $this->actingAs($admin)
            ->postJson("/api/devices/{$deviceId}/scan")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Perangkat sedang dalam mode Maintenance.');
    }

    public function test_guru_tidak_bisa_meminta_scan(): void
    {
        [, $deviceId] = $this->createDevice('Online');

        $this->actingAs($this->makeGuru())
            ->postJson("/api/devices/{$deviceId}/scan")
            ->assertForbidden();
    }

    private function deviceKey(int $deviceId): string
    {
        return $this->deviceKeys[$deviceId];
    }
}
