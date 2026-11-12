<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DeviceOfflineAlertTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function makeDevice(string $status, int $minutesAgo, ?string $alertedAt = null): Device
    {
        return Device::create([
            'device_id' => 'ESP32-OFF-'.uniqid(),
            'name' => 'Alat Offline',
            'status' => $status,
            'last_seen_at' => now()->subMinutes($minutesAgo),
            'offline_alerted_at' => $alertedAt === null ? null : now()->subHours((int) $alertedAt),
        ]);
    }

    public function test_perangkat_offline_lebih_dari_10_menit_membuat_notifikasi_admin(): void
    {
        $device = $this->makeDevice('Offline', 15);

        $this->artisan('devices:alert-offline')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'recipient_role' => 'admin',
            'type' => 'device',
            'title' => 'Perangkat Offline',
        ]);

        $this->assertNotNull($device->fresh()->offline_alerted_at);
    }

    public function test_perangkat_baru_offline_belum_diberi_notifikasi(): void
    {
        $this->makeDevice('Offline', 5);

        $this->artisan('devices:alert-offline')->assertSuccessful();

        $this->assertSame(0, Notification::where('type', 'device')->count());
    }

    public function test_notifikasi_tidak_diulang_dalam_jendela_6_jam(): void
    {
        $device = $this->makeDevice('Offline', 20);
        $device->update(['offline_alerted_at' => now()->subHours(2)]);

        $this->artisan('devices:alert-offline')->assertSuccessful();

        $this->assertSame(0, Notification::where('type', 'device')->count());
    }

    public function test_notifikasi_diulang_setelah_jendela_6_jam(): void
    {
        $device = $this->makeDevice('Offline', 20);
        $device->update(['offline_alerted_at' => now()->subHours(7)]);

        $this->artisan('devices:alert-offline')->assertSuccessful();

        $this->assertSame(1, Notification::where('type', 'device')->count());
        $this->assertTrue($device->fresh()->offline_alerted_at->gt(now()->subMinute()));
    }

    public function test_heartbeat_mereset_flag_alert_offline(): void
    {
        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'device_id' => 'ESP32-OFF-RESET',
            'name' => 'Alat Reset',
            'connection_type' => 'WiFi',
            'status' => 'Offline',
        ]);

        $response->assertCreated();
        $device = Device::findOrFail($response->json('id'));
        $device->update([
            'offline_alerted_at' => now()->subHour(),
            'last_seen_at' => now()->subHour(),
        ]);

        $this->withHeader('X-Device-Key', $response->json('device_key_plain'))
            ->postJson('/api/device/heartbeat', ['firmware_version' => '1.2.3'])
            ->assertOk()
            ->assertJsonPath('device.status', 'Online');

        $this->assertNull($device->fresh()->offline_alerted_at);
    }

    public function test_perangkat_maintenance_tidak_diberi_notifikasi(): void
    {
        $this->makeDevice('Maintenance', 30);

        $this->artisan('devices:alert-offline')->assertSuccessful();

        $this->assertSame(0, Notification::where('type', 'device')->count());
    }
}
