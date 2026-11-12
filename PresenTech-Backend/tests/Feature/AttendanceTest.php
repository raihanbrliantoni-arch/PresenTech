<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\RfidCard;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function setupSiswaDenganKartu(): array
    {
        $siswa = $this->makeSiswa('siswa1', '10001');
        $class = SchoolClass::create(['name' => 'XI RPL 1', 'major' => 'RPL', 'student_count' => 1]);
        $siswa->student->update(['class_id' => $class->id]);

        $card = RfidCard::create([
            'student_id' => $siswa->student->id,
            'uid' => 'UID123',
            'label' => 'Kartu Uji',
            'status' => 'Aktif',
            'registered_at' => now(),
        ]);

        return [$siswa->fresh(), $class, $card];
    }

    public function test_tap_kartu_dengan_device_key_berhasil(): void
    {
        $admin = $this->makeAdmin();
        [$siswa, $class, $card] = $this->setupSiswaDenganKartu();

        // Perangkat selalu memakai jam server, jadi waktu diuji dengan
        // membekukan jam (bukan lewat body request).
        $this->travelTo(Carbon::parse('2026-10-21 07:00:00'));

        $deviceResponse = $this->actingAs($admin)->postJson('/api/devices', [
            'device_id' => 'ESP32-01',
            'name' => 'ESP32 Gerbang',
            'connection_type' => 'WiFi',
        ]);
        $deviceResponse->assertCreated();
        $deviceKey = $deviceResponse->json('device_key_plain');
        $deviceId = $deviceResponse->json('id');

        $this->assertNotEmpty($deviceKey);

        $response = $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/attendance', [
                'uid' => 'UID123',
                'attendance_date' => '2000-01-01',
                'attendance_time' => '23:59:00',
            ]);

        $response->assertCreated()
            ->assertJsonPath('attendance.status', 'Hadir')
            ->assertJsonPath('attendance.device_id', $deviceId)
            ->assertJsonPath('attendance.source', 'RFID');

        // Tanggal/jam dari body harus diabaikan; yang tersimpan waktu server.
        $this->assertDatabaseHas('attendances', [
            'student_id' => $siswa->student->id,
            'attendance_date' => '2026-10-21',
        ]);
        $this->assertDatabaseMissing('attendances', [
            'student_id' => $siswa->student->id,
            'attendance_date' => '2000-01-01',
        ]);
    }

    public function test_nama_siswa_panjang_dipendekkan_untuk_layar_perangkat(): void
    {
        $admin = $this->makeAdmin();
        [$siswa] = $this->setupSiswaDenganKartu();
        $siswa->student->update(['name' => 'Muhammad Raihan Briliantoni Putra']);

        $this->travelTo(Carbon::parse('2026-10-21 07:00:00'));

        $deviceKey = $this->actingAs($admin)->postJson('/api/devices', [
            'device_id' => 'ESP32-LAYAR',
            'name' => 'ESP32 Gerbang',
            'connection_type' => 'WiFi',
        ])->json('device_key_plain');

        $response = $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/attendance', ['uid' => 'UID123']);

        // Field display dipakai layar alat: nama siswa sudah dipendekkan dan
        // tetap muat di LCD 16 kolom.
        $response->assertCreated()
            ->assertJsonPath('display.student_name', 'M. R. Putra')
            ->assertJsonPath('display.student_name_initials', 'M. R. B. Putra')
            ->assertJsonPath('display.class_name', 'XI RPL 1')
            ->assertJsonPath('display.status', 'Hadir')
            ->assertJsonPath('display.time', '07:00');
    }

    public function test_rotate_key_mengembalikan_key_baru_sekali_tampil(): void
    {
        $admin = $this->makeAdmin();

        $device = $this->actingAs($admin)->postJson('/api/devices', [
            'device_id' => 'ESP32-ROTATE',
            'name' => 'ESP32 Rotate',
            'connection_type' => 'WiFi',
        ])->assertCreated();

        $deviceId = $device->json('id');
        $oldKey = $device->json('device_key_plain');

        $rotated = $this->actingAs($admin)
            ->postJson("/api/devices/{$deviceId}/rotate-key")
            ->assertOk()
            ->assertJsonStructure(['device_key', 'device_key_plain']);

        $this->assertNotSame($oldKey, $rotated->json('device_key_plain'));
        $this->assertSame($rotated->json('device_key'), $rotated->json('device_key_plain'));
    }

    public function test_device_key_salah_ditolak(): void
    {
        [$siswa, $class, $card] = $this->setupSiswaDenganKartu();

        $this->withHeader('X-Device-Key', 'ptd_key_palsu')
            ->postJson('/api/attendance', ['uid' => 'UID123'])
            ->assertUnauthorized();
    }

    public function test_tanpa_autentikasi_tidak_bisa_catat_absensi(): void
    {
        [$siswa, $class, $card] = $this->setupSiswaDenganKartu();

        $this->postJson('/api/attendance', ['uid' => 'UID123'])->assertUnauthorized();
    }

    public function test_siswa_tidak_bisa_catat_absensi_manual(): void
    {
        $siswa = $this->makeSiswa('siswa1', '10001');

        $this->actingAs($siswa)
            ->postJson('/api/attendance', ['nis' => '10001'])
            ->assertUnauthorized();
    }

    public function test_guru_bisa_catat_absensi_manual(): void
    {
        [$siswa, $class, $card] = $this->setupSiswaDenganKartu();
        $guru = $this->makeGuru('guru1');

        $this->actingAs($guru)
            ->postJson('/api/attendance', [
                'nis' => '10001',
                'attendance_date' => now()->toDateString(),
                'attendance_time' => '07:00:00',
            ])
            ->assertCreated()
            ->assertJsonPath('attendance.status', 'Hadir');
    }

    public function test_double_tap_dalam_satu_menit_ditolak(): void
    {
        [$siswa, $class, $card] = $this->setupSiswaDenganKartu();
        $guru = $this->makeGuru('guru1');

        $first = $this->actingAs($guru)->postJson('/api/attendance', [
            'nis' => '10001',
            'attendance_date' => '2026-10-21',
            'attendance_time' => '07:10:00',
        ]);
        $first->assertCreated();

        // Tap kedua 30 detik kemudian ditolak sebagai double tap,
        // dan respons tetap memuat data siswa (bukan error null).
        $second = $this->actingAs($guru)->postJson('/api/attendance', [
            'nis' => '10001',
            'attendance_date' => '2026-10-21',
            'attendance_time' => '07:10:30',
        ]);
        $second->assertConflict()
            ->assertJsonPath('student.nis', '10001');

        // Tap beberapa menit kemudian tetap diterima.
        $third = $this->actingAs($guru)->postJson('/api/attendance', [
            'nis' => '10001',
            'attendance_date' => '2026-10-21',
            'attendance_time' => '07:30:00',
        ]);
        $third->assertCreated();
    }

    public function test_status_terlambat_mengikuti_jadwal_kelas(): void
    {
        [$siswa, $class, $card] = $this->setupSiswaDenganKartu();
        $guru = $this->makeGuru('guru1');

        $teacher = Teacher::first();
        $schedule = Schedule::create([
            'class_id' => $class->id,
            'teacher_id' => $teacher->id,
            'day' => 'Rabu',
            'start_time' => '07:00',
            'end_time' => '08:30',
            'subject' => 'Uji Terlambat',
            'is_active' => true,
        ]);

        // 07:05 dengan jadwal mulai 07:00 (via schedule_id) -> Terlambat.
        $this->actingAs($guru)->postJson('/api/attendance', [
            'nis' => '10001',
            'attendance_date' => '2026-10-21',
            'attendance_time' => '07:05:00',
            'schedule_id' => $schedule->id,
        ])->assertCreated()->assertJsonPath('attendance.status', 'Terlambat');

        // 06:55 (sebelum 07:00) -> Hadir.
        $this->actingAs($guru)->postJson('/api/attendance', [
            'nis' => '10001',
            'attendance_date' => '2026-10-22',
            'attendance_time' => '06:55:00',
            'schedule_id' => $schedule->id,
        ])->assertCreated()->assertJsonPath('attendance.status', 'Hadir');
    }

    public function test_perangkat_maintenance_tidak_bisa_tap(): void
    {
        $admin = $this->makeAdmin();
        [$siswa, $class, $card] = $this->setupSiswaDenganKartu();

        $deviceKey = $this->createDeviceignKey($admin, 'Maintenance');

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/attendance', ['uid' => 'UID123'])
            ->assertForbidden();
    }

    public function test_device_tap_dua_kali_sebelum_jam_pulang_ditolak(): void
    {
        $admin = $this->makeAdmin();
        [$siswa, $class, $card] = $this->setupSiswaDenganKartu();

        $deviceKey = $this->createDeviceignKey($admin, 'Online');
        $headers = ['X-Device-Key' => $deviceKey];

        $this->travelTo(Carbon::parse('2026-10-21 07:00:00'));

        $this->withHeaders($headers)->postJson('/api/attendance', ['uid' => 'UID123'])
            ->assertCreated();

        // Tap kedua pada TANGGAL sama sebelum jam pulang (14:00) tetap ditolak:
        // absensi dari perangkat hanya mencatat pulang setelah jam pulang.
        $this->travelTo(Carbon::parse('2026-10-21 08:00:00'));

        $second = $this->withHeaders($headers)->postJson('/api/attendance', ['uid' => 'UID123'])
            ->assertConflict()
            ->assertJsonPath('message', 'Siswa sudah absen masuk pukul 07:00. Tap pulang dibuka mulai 14:00.');

        $this->assertSame('10001', $second->json('student.nis'));
        $this->assertArrayHasKey('existing_attendance', $second->json());
    }

    public function test_device_tap_kedua_setelah_jam_pulang_mencatat_check_out(): void
    {
        $admin = $this->makeAdmin();
        [$siswa, $class, $card] = $this->setupSiswaDenganKartu();

        $deviceKey = $this->createDeviceignKey($admin, 'Online');
        $headers = ['X-Device-Key' => $deviceKey];

        $this->travelTo(Carbon::parse('2026-10-21 07:00:00'));

        $this->withHeaders($headers)->postJson('/api/attendance', ['uid' => 'UID123'])
            ->assertCreated();

        $this->travelTo(Carbon::parse('2026-10-21 14:30:00'));

        $this->withHeaders($headers)->postJson('/api/attendance', ['uid' => 'UID123'])
            ->assertOk()
            ->assertJsonPath('message', 'Absensi pulang berhasil dicatat pukul 14:30.')
            ->assertJsonPath('attendance.check_out_time', '14:30:00')
            ->assertJsonPath('attendance.attendance_time', '07:00:00');

        $this->assertDatabaseHas('attendances', [
            'student_id' => $siswa->student->id,
            'attendance_date' => '2026-10-21',
            'attendance_time' => '07:00:00',
            'check_out_time' => '14:30:00',
        ]);

        // Tap ketiga tidak boleh menimpa jam pulang.
        $this->travelTo(Carbon::parse('2026-10-21 14:45:00'));

        $this->withHeaders($headers)->postJson('/api/attendance', ['uid' => 'UID123'])
            ->assertConflict()
            ->assertJsonPath('message', 'Siswa sudah tap pulang pukul 14:30.');
    }

    public function test_jam_pulang_dari_pengaturan_langsung_dipakai_perangkat(): void
    {
        $admin = $this->makeAdmin();
        [$siswa, $class, $card] = $this->setupSiswaDenganKartu();

        Setting::current()->update(['home_time' => '12:00']);
        $deviceKey = $this->createDeviceignKey($admin, 'Online');
        $headers = ['X-Device-Key' => $deviceKey];

        $this->travelTo(Carbon::parse('2026-10-21 07:00:00'));
        $this->withHeaders($headers)->postJson('/api/attendance', ['uid' => 'UID123'])->assertCreated();

        $this->travelTo(Carbon::parse('2026-10-21 12:05:00'));
        $this->withHeaders($headers)->postJson('/api/attendance', ['uid' => 'UID123'])
            ->assertOk()
            ->assertJsonPath('attendance.check_out_time', '12:05:00');
    }

    public function test_admin_melengkapi_jam_pulang_pada_baris_yang_sudah_ada(): void
    {
        $admin = $this->makeAdmin();
        $this->setupSiswaDenganKartu();

        $this->actingAs($admin)->postJson('/api/attendance', [
            'nis' => '10001',
            'attendance_date' => '2026-10-21',
            'attendance_time' => '07:00:00',
            'status' => 'Hadir',
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/attendance', [
            'nis' => '10001',
            'attendance_date' => '2026-10-21',
            'attendance_time' => '10:00:00',
            'check_out_time' => '10:00:00',
            'notes' => 'Pulang lebih awal, ada keperluan keluarga.',
        ])->assertOk()
            ->assertJsonPath('message', 'Jam pulang berhasil dicatat.')
            ->assertJsonPath('attendance.attendance_time', '07:00:00')
            ->assertJsonPath('attendance.check_out_time', '10:00:00')
            ->assertJsonPath('attendance.early_leave', true);

        $this->assertSame(1, Attendance::whereDate('attendance_date', '2026-10-21')->count());
    }

    public function test_tap_beruntun_kartu_berbeda_ditandai_untuk_verifikasi(): void
    {
        $admin = $this->makeAdmin();
        [$siswa, $class, $card] = $this->setupSiswaDenganKartu();

        $siswaLain = $this->makeSiswa('siswa2', '10002');
        $siswaLain->student->update(['class_id' => $class->id]);
        RfidCard::create([
            'student_id' => $siswaLain->student->id,
            'uid' => 'UID456',
            'label' => 'Kartu Uji 2',
            'status' => 'Aktif',
            'registered_at' => now(),
        ]);

        $deviceKey = $this->createDeviceignKey($admin, 'Online');
        $headers = ['X-Device-Key' => $deviceKey];

        $this->travelTo(Carbon::parse('2026-10-21 07:00:00'));
        $this->withHeaders($headers)->postJson('/api/attendance', ['uid' => 'UID123'])->assertCreated();

        // Kartu teman ditap 2 detik kemudian: tetap dicatat, tetapi diberi
        // catatan agar Guru/Admin memeriksa kemungkinan titip kartu.
        $this->travelTo(Carbon::parse('2026-10-21 07:00:02'));
        $this->withHeaders($headers)->postJson('/api/attendance', ['uid' => 'UID456'])
            ->assertCreated()
            ->assertJsonPath('attendance.student.nis', '10002');

        $this->assertStringContainsString(
            'Tap beruntun',
            (string) Attendance::where('student_id', $siswaLain->student->id)->value('notes'),
        );
    }

    public function test_admin_mencatat_pulang_lebih_awal_otomatis_ditandai_early_leave(): void
    {
        $admin = $this->makeAdmin();
        [$siswa, $class, $card] = $this->setupSiswaDenganKartu();

        $this->actingAs($admin)->postJson('/api/attendance', [
            'nis' => '10001',
            'attendance_date' => '2026-10-21',
            'attendance_time' => '07:00:00',
            'check_out_time' => '10:00:00',
            'notes' => 'Pulang karena sakit.',
        ])->assertCreated()
            ->assertJsonPath('attendance.check_out_time', '10:00:00')
            ->assertJsonPath('attendance.early_leave', true);
    }

    public function test_perangkat_tidak_bisa_menerapkan_status_palsu(): void
    {
        $admin = $this->makeAdmin();
        [$siswa, $class, $card] = $this->setupSiswaDenganKartu();

        $deviceKey = $this->createDeviceignKey($admin, 'Online');

        // Jam dipatok Rabu 10:00 (batas terlambat 07:15) supaya hasilnya tidak
        // bergantung hari saat tes dijalankan.
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));

        // Perangkat mencoba memaksa status "Hadir" padahal jam masuk lewat:
        // status tetap dihitung otomatis -> Terlambat.
        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/attendance', [
                'uid' => 'UID123',
                'attendance_date' => '2026-10-21',
                'attendance_time' => '10:00:00',
                'status' => 'Hadir',
            ])
            ->assertCreated()
            ->assertJsonPath('attendance.status', 'Terlambat');
    }

    public function test_tap_kartu_dengan_uid_huruf_kecil_dan_pemisah_tetap_dikenali(): void
    {
        $admin = $this->makeAdmin();
        $siswa = $this->makeSiswa('siswa-uid', '10002');
        $card = RfidCard::create([
            'student_id' => $siswa->student->id,
            'uid' => 'A1B2C3D4',
            'label' => 'Kartu UID',
            'status' => 'Aktif',
            'registered_at' => now(),
        ]);
        $deviceKey = $this->createDeviceignKey($admin);

        $this->travelTo(Carbon::parse('2026-10-25 07:00:00'));

        $this->withHeader('X-Device-Key', $deviceKey)
            ->postJson('/api/attendance', ['uid' => 'a1:b2:c3:d4'])
            ->assertCreated()
            ->assertJsonPath('attendance.student.nis', '10002')
            ->assertJsonPath('attendance.uid_snapshot', 'A1B2C3D4')
            ->assertJsonPath('attendance.source', 'RFID');

        $this->assertDatabaseHas('attendances', [
            'student_id' => $siswa->student->id,
            'rfid_card_id' => $card->id,
            'uid_snapshot' => 'A1B2C3D4',
        ]);
    }

    public function test_filter_absensi_tidak_valid_ditolak_bukan_error_server(): void
    {
        $admin = $this->makeAdmin();

        // Sebelumnya `?date=abc` melempar 500 karena Carbon::parse.
        $this->actingAs($admin)->getJson('/api/attendance?date=abc')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/attendance?status=Ngasal')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/attendance?per_page=0')->assertUnprocessable();

        $this->actingAs($admin)->getJson('/api/attendance?date=2026-10-21')->assertOk();
    }

    private function createDeviceignKey($admin, string $status = 'Online'): string
    {
        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'device_id' => 'ESP32-KEY-'.uniqid(),
            'name' => 'ESP32 Uji',
            'connection_type' => 'WiFi',
            'status' => $status,
        ]);
        $response->assertCreated();

        return $response->json('device_key_plain');
    }
}
