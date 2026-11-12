<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\RfidCard;
use App\Models\SchoolClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DeviceAttendanceBatchTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private string $deviceKey;

    private int $deviceId;

    private int $studentId;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'device_id' => 'ESP32-BATCH-01',
            'name' => 'ESP32 Batch',
            'connection_type' => 'WiFi',
            'status' => 'Online',
        ])->assertCreated();

        $this->deviceId = $response->json('id');
        $this->deviceKey = $response->json('device_key_plain');

        $siswa = $this->makeSiswa('siswa1', '10001');
        $class = SchoolClass::create(['name' => 'XI RPL 1', 'major' => 'RPL', 'student_count' => 1]);
        $siswa->student->update(['class_id' => $class->id]);
        $this->studentId = $siswa->student->id;

        RfidCard::create([
            'student_id' => $this->studentId,
            'uid' => '039D7641',
            'label' => 'Kartu Uji',
            'status' => 'Aktif',
            'registered_at' => now(),
        ]);
    }

    public function test_batch_mencatat_absensi_dengan_waktu_dari_perangkat(): void
    {
        $tappedAt = Carbon::parse('2026-10-28 06:45:00');
        // Waktu server harus dekat dengan waktu tap agar event dianggap wajar
        // (batas antrean offline 7 hari) dan hasilnya deterministik.
        $this->travelTo($tappedAt->copy()->addMinutes(2));

        $response = $this->withHeader('X-Device-Key', $this->deviceKey)
            ->postJson('/api/device/attendance/batch', [
                'events' => [
                    [
                        'client_event_id' => 'evt-1',
                        'uid' => '039D7641',
                        'tapped_at' => $tappedAt->toIso8601String(),
                        'time_verified' => true,
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('results.0.client_event_id', 'evt-1')
            ->assertJsonPath('results.0.outcome', 'created')
            ->assertJsonPath('results.0.status_code', 201);

        $this->assertDatabaseHas('attendances', [
            'student_id' => $this->studentId,
            'device_id' => $this->deviceId,
            'client_event_id' => 'evt-1',
            'attendance_date' => '2026-10-28',
            'attendance_time' => '06:45:00',
            'status' => 'Hadir',
            'notes' => null,
        ]);
    }

    public function test_kiriman_ulang_event_yang_sama_tidak_membuat_baris_baru(): void
    {
        $payload = [
            'events' => [
                ['client_event_id' => 'evt-dup', 'uid' => '039D7641'],
            ],
        ];

        $this->withHeader('X-Device-Key', $this->deviceKey)
            ->postJson('/api/device/attendance/batch', $payload)
            ->assertOk()
            ->assertJsonPath('results.0.outcome', 'created');

        $this->withHeader('X-Device-Key', $this->deviceKey)
            ->postJson('/api/device/attendance/batch', $payload)
            ->assertOk()
            ->assertJsonPath('results.0.outcome', 'duplicate');

        $this->assertSame(1, Attendance::where('client_event_id', 'evt-dup')->count());
    }

    public function test_waktu_yang_belum_terverifikasi_ditandai_di_catatan(): void
    {
        $this->withHeader('X-Device-Key', $this->deviceKey)
            ->postJson('/api/device/attendance/batch', [
                'events' => [
                    [
                        'client_event_id' => 'evt-unverified',
                        'uid' => '039D7641',
                        'tapped_at' => null,
                        'time_verified' => false,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('results.0.outcome', 'created');

        $attendance = Attendance::where('client_event_id', 'evt-unverified')->firstOrFail();

        $this->assertStringContainsString('belum terverifikasi', (string) $attendance->notes);
    }

    public function test_waktu_masa_depan_diganti_jam_server_dan_ditandai(): void
    {
        $this->withHeader('X-Device-Key', $this->deviceKey)
            ->postJson('/api/device/attendance/batch', [
                'events' => [
                    [
                        'client_event_id' => 'evt-future',
                        'uid' => '039D7641',
                        'tapped_at' => now()->addDay()->toIso8601String(),
                        'time_verified' => true,
                    ],
                ],
            ])
            ->assertOk();

        $attendance = Attendance::where('client_event_id', 'evt-future')->firstOrFail();

        $this->assertSame(now()->toDateString(), $attendance->attendance_date->toDateString());
        $this->assertStringContainsString('belum terverifikasi', (string) $attendance->notes);
    }

    public function test_uid_tak_terdaftar_dilaporkan_per_event_tanpa_gagal_seluruh_batch(): void
    {
        $this->withHeader('X-Device-Key', $this->deviceKey)
            ->postJson('/api/device/attendance/batch', [
                'events' => [
                    ['client_event_id' => 'evt-ok', 'uid' => '039D7641'],
                    ['client_event_id' => 'evt-unknown', 'uid' => 'TIDAK-ADA'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('results.0.outcome', 'created')
            ->assertJsonPath('results.1.outcome', 'unregistered')
            ->assertJsonPath('results.1.status_code', 404);

        $this->assertSame(1, Attendance::count());
    }

    public function test_device_key_salah_ditolak(): void
    {
        $this->withHeader('X-Device-Key', 'kunci-palsu')
            ->postJson('/api/device/attendance/batch', [
                'events' => [
                    ['client_event_id' => 'evt-1', 'uid' => '039D7641'],
                ],
            ])
            ->assertUnauthorized();
    }

    public function test_event_duplikat_dalam_satu_batch_ditolak_validasi(): void
    {
        $this->withHeader('X-Device-Key', $this->deviceKey)
            ->postJson('/api/device/attendance/batch', [
                'events' => [
                    ['client_event_id' => 'evt-sama', 'uid' => '039D7641'],
                    ['client_event_id' => 'evt-sama', 'uid' => '039D7641'],
                ],
            ])
            ->assertUnprocessable();
    }
}
