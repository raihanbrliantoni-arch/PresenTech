<?php

namespace Tests\Feature;

use App\Console\Commands\DeviceMqttListenCommand;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DeviceMqttTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function makeDevice(): Device
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'device_id' => 'ESP32-MQTT-'.uniqid(),
            'name' => 'ESP32 MQTT',
            'connection_type' => 'WiFi',
            'status' => 'Online',
        ])->assertCreated();

        return Device::findOrFail($response->json('id'));
    }

    /**
     * Firmware lama mengirim hash huruf besar, sedangkan device_key tersimpan
     * huruf kecil. Bridge harus tetap menemukan perangkatnya.
     */
    public function test_hash8_mqtt_dicocokkan_tanpa_memperhatikan_huruf_besar_kecil(): void
    {
        $device = $this->makeDevice();
        $hash8 = substr((string) $device->device_key, 0, 8);

        $command = app(DeviceMqttListenCommand::class);
        $method = new ReflectionMethod($command, 'resolveDeviceByHash');
        $method->setAccessible(true);

        $this->assertSame($device->id, $method->invoke($command, strtoupper($hash8))?->id);
        $this->assertSame($device->id, $method->invoke($command, strtolower($hash8))?->id);
        $this->assertNull($method->invoke($command, 'deadbeef'));
        $this->assertNull($method->invoke($command, '%_______'));
    }

    public function test_laporan_ota_lewat_mqtt_memperbarui_versi_perangkat(): void
    {
        $device = $this->makeDevice();
        $device->update(['ota_status' => 'pending', 'ota_requested_at' => now()]);

        $command = app(DeviceMqttListenCommand::class);
        $method = new ReflectionMethod($command, 'handleDeviceResult');
        $method->setAccessible(true);

        $method->invoke($command, $device, [
            'kind' => 'ota_ack',
            'status' => 'success',
            'version' => '1.2.0',
        ]);

        $device->refresh();
        $this->assertSame('success', $device->ota_status);
        $this->assertSame('1.2.0', $device->firmware_version);
        $this->assertNull($device->ota_requested_at);
    }
}
