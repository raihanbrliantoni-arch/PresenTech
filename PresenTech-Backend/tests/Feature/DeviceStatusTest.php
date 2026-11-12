<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DeviceStatusTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_dashboard_menampilkan_status_live_dari_last_seen_at_tanpa_menunggu_scheduler(): void
    {
        $admin = $this->makeAdmin();

        $fresh = Device::create([
            'device_id' => 'ESP32-LIVE',
            'name' => 'ESP32 Live',
            'status' => 'Offline', // kolom tersimpan masih Offline
            'last_seen_at' => now()->subSeconds(10),
        ]);
        $stale = Device::create([
            'device_id' => 'ESP32-STALE-LIST',
            'name' => 'ESP32 Stale',
            'status' => 'Online', // kolom tersimpan masih Online
            'last_seen_at' => now()->subMinutes(3),
        ]);
        $maintenance = Device::create([
            'device_id' => 'ESP32-MAINT-LIST',
            'name' => 'ESP32 Maintenance',
            'status' => 'Maintenance',
            'last_seen_at' => now()->subMinutes(10),
        ]);
        $never = Device::create([
            'device_id' => 'ESP32-NEVER',
            'name' => 'ESP32 Baru',
            'status' => 'Offline',
            'last_seen_at' => null,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/devices?per_page=100')
            ->assertOk()
            ->assertJsonCount(4, 'data');

        // Status per item: baris "fresh" Online meski kolom Offline,
        // baris "stale" Offline meski kolom Online, maintenance tetap.
        $statuses = collect($response->json('data'))->mapWithKeys(
            fn (array $row) => [$row['device_id'] => $row['status']]
        );

        $this->assertSame('Online', $statuses['ESP32-LIVE']);
        $this->assertSame('Offline', $statuses['ESP32-STALE-LIST']);
        $this->assertSame('Maintenance', $statuses['ESP32-MAINT-LIST']);
        $this->assertSame('Offline', $statuses['ESP32-NEVER']);

        // Kolom status di database tidak diubah oleh pembacaan (live saja).
        $this->assertSame('Offline', $fresh->fresh()->status);
        $this->assertSame('Online', $stale->fresh()->status);
    }
}
