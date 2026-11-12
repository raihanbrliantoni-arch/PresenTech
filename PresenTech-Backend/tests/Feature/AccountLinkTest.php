<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AccountLinkTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_akun_berperan_lain_tidak_bisa_ditautkan_ke_siswa(): void
    {
        $admin = $this->makeAdmin();
        $akunGuru = $this->makeGuru('guru-link');

        $this->actingAs($admin)->postJson('/api/students', [
            'user_id' => $akunGuru->id,
            'nis' => '40001',
            'name' => 'Siswa Salah Peran',
        ])->assertUnprocessable();
    }

    public function test_satu_akun_siswa_tidak_bisa_ditautkan_ke_dua_siswa(): void
    {
        $admin = $this->makeAdmin();
        $akun = $this->makeUser('siswa', 'akun-siswa', 'password123');

        $this->actingAs($admin)->postJson('/api/students', [
            'user_id' => $akun->id,
            'nis' => '40002',
            'name' => 'Siswa Pertama',
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/students', [
            'user_id' => $akun->id,
            'nis' => '40003',
            'name' => 'Siswa Kedua',
        ])->assertUnprocessable();

        $this->assertSame(1, Student::where('user_id', $akun->id)->count());
    }

    public function test_update_siswa_dengan_akun_sendiri_tetap_diizinkan(): void
    {
        $admin = $this->makeAdmin();
        $akun = $this->makeUser('siswa', 'akun-sendiri', 'password123');
        $siswa = Student::create([
            'user_id' => $akun->id,
            'nis' => '40004',
            'name' => 'Siswa Sendiri',
            'status' => 'Aktif',
        ]);

        $this->actingAs($admin)->putJson('/api/students/'.$siswa->id, [
            'user_id' => $akun->id,
            'name' => 'Siswa Sendiri Diubah',
        ])->assertOk();
    }

    public function test_akun_siswa_tidak_bisa_ditautkan_ke_guru(): void
    {
        $admin = $this->makeAdmin();
        $akunSiswa = $this->makeUser('siswa', 'akun-siswa-2', 'password123');

        $this->actingAs($admin)->postJson('/api/teachers', [
            'user_id' => $akunSiswa->id,
            'nip' => '50001',
            'name' => 'Guru Salah Peran',
        ])->assertUnprocessable();
    }

    public function test_satu_akun_guru_tidak_bisa_ditautkan_ke_dua_guru(): void
    {
        $admin = $this->makeAdmin();
        $akun = $this->makeUser('guru', 'akun-guru', 'password123');

        $this->actingAs($admin)->postJson('/api/teachers', [
            'user_id' => $akun->id,
            'nip' => '50002',
            'name' => 'Guru Pertama',
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/teachers', [
            'user_id' => $akun->id,
            'nip' => '50003',
            'name' => 'Guru Kedua',
        ])->assertUnprocessable();

        $this->assertSame(1, Teacher::where('user_id', $akun->id)->count());
    }
}
