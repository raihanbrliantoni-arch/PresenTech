<?php

namespace Tests\Feature;

use App\Models\RfidCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class RfidCardTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_admin_menyimpan_kartu_dengan_uid_huruf_kecil_menjadi_uppercase(): void
    {
        $admin = $this->makeAdmin();
        $siswa = $this->makeSiswa('siswa-kartu', '10003');

        $response = $this->actingAs($admin)->postJson('/api/rfid-cards', [
            'student_id' => $siswa->student->id,
            'uid' => 'a1:b2:c3:d4',
            'label' => 'Kartu Uji',
            'status' => 'Aktif',
        ]);

        $response->assertCreated()
            ->assertJsonPath('uid', 'A1B2C3D4')
            ->assertJsonPath('status', 'Aktif');

        $this->assertDatabaseHas('rfid_cards', [
            'student_id' => $siswa->student->id,
            'uid' => 'A1B2C3D4',
        ]);
    }

    public function test_admin_tidak_bisa_mendaftarkan_uid_sama_dengan_format_berbeda(): void
    {
        $admin = $this->makeAdmin();
        $siswa = $this->makeSiswa('siswa-kartu', '10003');

        RfidCard::create([
            'student_id' => $siswa->student->id,
            'uid' => 'A1B2C3D4',
            'status' => 'Aktif',
            'registered_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/api/rfid-cards', [
            'student_id' => $siswa->student->id,
            'uid' => 'a1b2c3d4',
        ])->assertUnprocessable();

        $this->assertSame(1, RfidCard::where('uid', 'A1B2C3D4')->count());
    }

    public function test_filter_uid_menormalkan_input_sebelum_mencari(): void
    {
        $admin = $this->makeAdmin();
        $siswa = $this->makeSiswa('siswa-kartu', '10003');

        RfidCard::create([
            'student_id' => $siswa->student->id,
            'uid' => 'A1B2C3D4',
            'status' => 'Aktif',
            'registered_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/rfid-cards?uid=a1:b2:c3:d4')
            ->assertOk()
            ->assertJsonPath('data.0.uid', 'A1B2C3D4')
            ->assertJsonPath('total', 1);
    }
}
