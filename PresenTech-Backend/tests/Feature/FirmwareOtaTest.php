<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceFirmware;
use App\Models\RfidCard;
use App\Models\Setting;
use App\Models\User;
use App\Support\DeviceOta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class FirmwareOtaTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private ?User $admin = null;

    /**
     * Satu Admin per test supaya username unik tidak bentrok.
     */
    private function admin(): User
    {
        return $this->admin ??= $this->makeAdmin();
    }

    /**
     * @return array{0: Device, 1: string}
     */
    private function makeDevice(string $status = 'Online'): array
    {
        $response = $this->actingAs($this->admin())->postJson('/api/devices', [
            'device_id' => 'ESP32-OTA-'.uniqid(),
            'name' => 'ESP32 OTA',
            'connection_type' => 'WiFi',
            'status' => $status,
            'firmware_version' => '1.0.0',
        ])->assertCreated();

        return [Device::findOrFail($response->json('id')), $response->json('device_key_plain')];
    }

    private function uploadFirmware(string $version = '1.2.0', string $content = 'firmware-binary'): DeviceFirmware
    {
        $response = $this->actingAs($this->admin())->post('/api/firmwares', [
            'version' => $version,
            'notes' => 'Rilis uji',
            'file' => UploadedFile::fake()->createWithContent(
                'presentech-'.$version.'.bin',
                $content,
            ),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('firmware.version', $version)
            ->assertJsonPath('firmware.is_active', true);

        $firmware = DeviceFirmware::findOrFail($response->json('firmware.id'));

        Storage::disk('local')->assertExists($firmware->path);

        return $firmware;
    }

    public function test_admin_mengunggah_firmware_dengan_checksum_dan_ukuran(): void
    {
        $firmware = $this->uploadFirmware('1.2.0', 'abc123');

        $this->assertSame(6, $firmware->size);
        $this->assertSame(hash('sha256', 'abc123'), $firmware->checksum);

        $this->actingAs($this->admin())
            ->getJson('/api/firmwares')
            ->assertOk()
            ->assertJsonPath('latest', '1.2.0')
            ->assertJsonPath('firmwares.0.version', '1.2.0')
            ->assertJsonPath('firmwares.0.file_available', true);
    }

    public function test_versi_firmware_duplikat_ditolak(): void
    {
        $this->uploadFirmware('1.2.0');

        $this->actingAs($this->admin())
            ->post('/api/firmwares', [
                'version' => '1.2.0',
                'file' => UploadedFile::fake()->createWithContent('sama.bin', 'lagi'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('version');
    }

    public function test_hanya_admin_yang_boleh_mengunggah_firmware(): void
    {
        $this->actingAs($this->makeGuru())
            ->post('/api/firmwares', [
                'version' => '9.9.9',
                'file' => UploadedFile::fake()->createWithContent('guru.bin', 'x'),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_ota_mengirim_url_bertanda_tangan_pada_heartbeat(): void
    {
        [$device, $deviceKey] = $this->makeDevice();
        $firmware = $this->uploadFirmware('1.2.0');

        $this->actingAs($this->admin())
            ->postJson("/api/devices/{$device->id}/ota", ['firmware_id' => $firmware->id])
            ->assertOk()
            ->assertJsonPath('ota.version', '1.2.0')
            ->assertJsonPath('device.ota_status', 'pending');

        $response = $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/heartbeat')
            ->assertOk()
            ->assertJsonPath('ota.version', '1.2.0')
            ->assertJsonPath('ota.size', $firmware->size)
            ->assertJsonPath('ota.sha256', $firmware->checksum)
            ->assertJsonPath('firmware.current', '1.0.0')
            ->assertJsonPath('firmware.latest', '1.2.0')
            ->assertJsonPath('firmware.update_available', true);

        $url = $response->json('ota.url');
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('/api/firmware/'.$firmware->id.'/download', $url);

        $this->get($url)->assertOk();
    }

    public function test_url_unduhan_tanpa_tanda_tangan_ditolak(): void
    {
        $firmware = $this->uploadFirmware('1.2.0');

        $this->get("/api/firmware/{$firmware->id}/download")->assertForbidden();
    }

    public function test_laporan_sukses_ota_memperbarui_versi_dan_menghapus_permintaan(): void
    {
        [$device, $deviceKey] = $this->makeDevice();
        $firmware = $this->uploadFirmware('1.2.0');

        $this->actingAs($this->admin())
            ->postJson("/api/devices/{$device->id}/ota", ['firmware_id' => $firmware->id])
            ->assertOk();

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/ota-ack', [
                'status' => 'success',
                'version' => '1.2.0',
                'message' => 'Update selesai, restart otomatis.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('firmware_version', '1.2.0');

        $device->refresh();
        $this->assertSame('1.2.0', $device->firmware_version);
        $this->assertSame('success', $device->ota_status);
        $this->assertNull($device->ota_requested_at);
        $this->assertNull($device->ota_firmware_id);

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/heartbeat')
            ->assertOk()
            ->assertJsonPath('ota', null)
            ->assertJsonPath('firmware.update_available', false);
    }

    public function test_laporan_gagal_ota_menghentikan_permintaan_dan_dicatat(): void
    {
        [$device, $deviceKey] = $this->makeDevice();
        $firmware = $this->uploadFirmware('1.2.0');

        $this->actingAs($this->admin())
            ->postJson("/api/devices/{$device->id}/ota", ['firmware_id' => $firmware->id])
            ->assertOk();

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/device/ota-ack', [
                'status' => 'failed',
                'message' => 'Checksum tidak cocok',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'failed');

        $device->refresh();
        $this->assertSame('failed', $device->ota_status);
        $this->assertNull($device->ota_requested_at);
        $this->assertSame('1.0.0', $device->firmware_version);
        $this->assertDatabaseHas('system_logs', ['action' => 'device.ota_failed', 'device_id' => $device->id]);
    }

    public function test_ota_dibatalkan_dari_dashboard(): void
    {
        [$device] = $this->makeDevice();
        $firmware = $this->uploadFirmware('1.2.0');

        $this->actingAs($this->admin())
            ->postJson("/api/devices/{$device->id}/ota", ['firmware_id' => $firmware->id])
            ->assertOk();

        $this->actingAs($this->admin())
            ->deleteJson("/api/devices/{$device->id}/ota")
            ->assertOk()
            ->assertJsonPath('device.ota_status', 'canceled');

        $this->assertNull($device->fresh()->ota_requested_at);
    }

    public function test_ota_ditolak_bila_versi_sama_atau_perangkat_maintenance(): void
    {
        [$device] = $this->makeDevice();
        $firmware = $this->uploadFirmware('1.2.0');

        $this->actingAs($this->admin())
            ->postJson("/api/devices/{$device->id}/ota", ['firmware_id' => $firmware->id])
            ->assertOk();

        $device->update(['firmware_version' => '1.2.0']);

        $this->actingAs($this->admin())
            ->postJson("/api/devices/{$device->id}/ota", ['firmware_id' => $firmware->id])
            ->assertStatus(409);

        $device->update(['status' => 'Maintenance', 'firmware_version' => '1.0.0']);

        $this->actingAs($this->admin())
            ->postJson("/api/devices/{$device->id}/ota", ['firmware_id' => $firmware->id])
            ->assertStatus(409);
    }

    public function test_payload_ota_kosong_setelah_firmware_dihapus(): void
    {
        [$device] = $this->makeDevice();
        $firmware = $this->uploadFirmware('1.2.0');

        DeviceOta::request($device, $firmware);
        $this->assertNotNull(DeviceOta::pending($device->fresh()));

        $this->actingAs($this->admin())
            ->deleteJson("/api/firmwares/{$firmware->id}")
            ->assertOk();

        $this->assertNull(DeviceOta::pending($device->fresh()));
        Storage::disk('local')->assertMissing($firmware->path);
    }

    public function test_absensi_http_mengirim_jam_pulang_dan_outcome_ke_alat(): void
    {
        Carbon::setTestNow('2026-11-03 15:30:00');
        Setting::current()->update(['home_time' => '14:00']);
        Setting::current()->refresh();

        [$device, $deviceKey] = $this->makeDevice();

        $siswa = $this->makeSiswa('siswaota', '20001');
        $student = $siswa->student;
        $student->update(['class_id' => null]);

        RfidCard::create([
            'uid' => 'A1B2C3D4',
            'student_id' => $student->id,
            'status' => 'Aktif',
        ]);

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/attendance', ['uid' => 'A1B2C3D4'])
            ->assertCreated()
            ->assertJsonPath('outcome', 'created')
            ->assertJsonPath('home_time', '14:00')
            ->assertJsonPath('check_out_active', true)
            ->assertJsonPath('server_date', '2026-11-03');

        Carbon::setTestNow();
    }
}
