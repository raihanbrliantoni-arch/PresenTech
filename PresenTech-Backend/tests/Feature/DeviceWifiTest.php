<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DeviceWifiTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /**
     * @return array{0: User, 1: int, 2: string}
     */
    private function createDevice(): array
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'device_id' => 'ESP32-WIFI-'.uniqid(),
            'name' => 'ESP32 Uji WiFi',
            'connection_type' => 'WiFi',
            'status' => 'Online',
        ]);

        $response->assertCreated();

        return [$admin, $response->json('id'), $response->json('device_key_plain')];
    }

    public function test_admin_menyimpan_wifi_terenkripsi_lalu_heartbeat_mengirim_kredensial(): void
    {
        [$admin, $deviceId, $deviceKey] = $this->createDevice();

        $this->actingAs($admin)
            ->postJson("/api/devices/{$deviceId}/wifi", [
                'ssid' => 'WiFi Sekolah',
                'password' => 'rahasia-sekolah',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('wifi_ssid', 'WiFi Sekolah')
            ->assertJsonMissingPath('wifi_password');

        // Password tidak boleh tersimpan sebagai teks biasa.
        $raw = DB::table('devices')->where('id', $deviceId)->value('wifi_password');
        $this->assertNotSame('rahasia-sekolah', $raw);
        $this->assertSame('rahasia-sekolah', Device::findOrFail($deviceId)->wifi_password);

        $response = $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/heartbeat')
            ->assertOk()
            ->assertJsonPath('wifi_setup_ssid', 'WiFi Sekolah')
            ->assertJsonPath('wifi_setup_password', 'rahasia-sekolah');

        $this->assertNotEmpty($response->json('wifi_setup_token'));
    }

    public function test_ack_membersihkan_password_dan_heartbeat_berikutnya_tidak_mengirim_wifi(): void
    {
        [$admin, $deviceId, $deviceKey] = $this->createDevice();

        $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/wifi", [
            'ssid' => 'WiFi Baru',
            'password' => 'sandi-baru',
        ])->assertOk();

        $token = Device::findOrFail($deviceId)->wifi_token;

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/wifi-ack', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('wifi_ssid', 'WiFi Baru');

        $device = Device::findOrFail($deviceId);
        $this->assertNull($device->wifi_password);
        $this->assertNull($device->wifi_token);
        $this->assertNull($device->wifi_requested_at);
        $this->assertSame('WiFi Baru', $device->wifi_ssid);

        $response = $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/heartbeat')
            ->assertOk();

        $this->assertArrayNotHasKey('wifi_setup_ssid', $response->json());
    }

    public function test_ack_dengan_token_salah_ditolak_dan_konfigurasi_tetap_menunggu(): void
    {
        [$admin, $deviceId, $deviceKey] = $this->createDevice();

        $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/wifi", [
            'ssid' => 'WiFi Baru',
            'password' => 'sandi-baru',
        ])->assertOk();

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/wifi-ack', ['token' => 'token-palsu'])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $device = Device::findOrFail($deviceId);
        $this->assertNotNull($device->wifi_requested_at);
        $this->assertNotNull($device->wifi_password);
    }

    public function test_ganti_wifi_hanya_bisa_dilakukan_admin(): void
    {
        [, $deviceId] = $this->createDevice();
        $guru = $this->makeGuru();

        $this->actingAs($guru)
            ->postJson("/api/devices/{$deviceId}/wifi", [
                'ssid' => 'WiFi Tidak Sah',
                'password' => 'sandi',
            ])
            ->assertForbidden();
    }
}
