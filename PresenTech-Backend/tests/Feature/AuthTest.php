<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_login_berhasil_dengan_kredensial_benar(): void
    {
        $user = $this->makeSiswa('siswa1', '10001');

        $response = $this->postJson('/api/login', [
            'identifier' => '10001',
            'password' => 'siswa12345',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'role']]);

        $this->assertSame('siswa', $response->json('user.role'));
    }

    public function test_login_gagal_dengan_password_salah(): void
    {
        $user = $this->makeSiswa('siswa1', '10001');

        $this->postJson('/api/login', [
            'identifier' => '10001',
            'password' => 'salahbanget',
        ])->assertUnprocessable();
    }

    public function test_login_dibatasi_setelah_percobaan_berulang(): void
    {
        $user = $this->makeSiswa('siswa1', '10001');

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', [
                'identifier' => '10001',
                'password' => 'salahbanget',
            ]);
        }

        $this->postJson('/api/login', [
            'identifier' => '10001',
            'password' => 'salahbanget',
        ])->assertStatus(429);
    }

    public function test_registrasi_publik_sudah_tidak_tersedia(): void
    {
        $this->postJson('/api/register', [
            'role' => 'siswa',
            'identifier' => '90001',
            'name' => 'Regi Baru',
            'password' => 'password123',
        ])->assertNotFound();
    }

    public function test_admin_membuat_akun_siswa_lalu_bisa_login(): void
    {
        $admin = $this->makeAdmin();
        $student = Student::create([
            'nis' => '90001',
            'name' => 'Regi Baru',
            'status' => 'Aktif',
            'class_id' => null,
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/api/students/'.$student->id.'/account');

        $response->assertCreated();
        // Username sekaligus password awal = NIS, jadi bisa langsung dipakai.
        $this->assertSame('90001', $response->json('initial_password'));

        $user = User::where('username', '90001')->first();
        $this->assertNotNull($user);
        // Role berasal dari jenis data personel, bukan input request.
        $this->assertSame('siswa', $user->role);
        $this->assertNotNull($user->student);
        // Password awal NIS/NIP mudah ditebak, jadi wajib diganti saat login.
        $this->assertTrue($user->must_change_password);

        $this->postJson('/api/login', [
            'identifier' => '90001',
            'password' => '90001',
        ])->assertOk()
            ->assertJsonPath('user.must_change_password', true);
    }

    public function test_password_pilihan_admin_wajib_diganti_sebelum_memakai_fitur(): void
    {
        $admin = $this->makeAdmin();
        $student = Student::create([
            'nis' => '90003',
            'name' => 'Wajib Ganti',
            'status' => 'Aktif',
            'class_id' => null,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/students/'.$student->id.'/account')
            ->assertCreated();

        $user = User::where('username', '90003')->firstOrFail();

        // Admin memilih password sendiri (bukan NIS) — pengguna wajib ganti.
        $this->actingAs($admin)
            ->postJson('/api/admin/users/'.$user->id.'/set-password', [
                'password' => 'sementaraku1',
            ])
            ->assertOk();

        $login = $this->postJson('/api/login', [
            'identifier' => '90003',
            'password' => 'sementaraku1',
        ])->assertOk();

        $this->assertTrue($login->json('user.must_change_password'));
        $token = $login->json('token');

        // Lepas sesi Admin dari guard test agar request memakai token siswa.
        $this->app['auth']->forgetGuards();

        // Endpoint lain diblokir sampai password sementara diganti.
        $this->withToken($token)->getJson('/api/schedules')->assertStatus(428);

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson('/api/change-password', [
            'current_password' => 'sementaraku1',
            'password' => 'passwordbaru1',
            'password_confirmation' => 'passwordbaru1',
        ])->assertOk();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('passwordbaru1', $user->password));
    }

    public function test_admin_reset_password_tanpa_password_pilihan_memakai_username(): void
    {
        $siswa = $this->makeSiswa('siswa1', '10001');
        $admin = $this->makeAdmin();
        $siswa->createToken('lama');

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/users/'.$siswa->id.'/set-password')
            ->assertOk();

        // Tanpa password pilihan Admin, password sementara = username
        // (NIS/NIP) dan tetap wajib diganti saat login.
        $this->assertSame('siswa1', $response->json('initial_password'));
        $this->assertTrue($response->json('must_change_password'));
        $this->assertTrue($siswa->fresh()->must_change_password);
        $this->assertSame(0, $siswa->fresh()->tokens()->count());
    }

    public function test_akun_tidak_dibuat_untuk_siswa_nonaktif(): void
    {
        $admin = $this->makeAdmin();
        $student = Student::create([
            'nis' => '90004',
            'name' => 'Belum Aktif',
            'status' => 'Nonaktif',
            'class_id' => null,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/students/'.$student->id.'/account')
            ->assertUnprocessable();

        $this->assertNull(User::where('username', '90004')->first());
    }

    public function test_akun_tidak_bisa_dibuat_dua_kali(): void
    {
        $admin = $this->makeAdmin();
        $student = Student::create([
            'nis' => '90002',
            'name' => 'Sekali Saja',
            'status' => 'Aktif',
            'class_id' => null,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/students/'.$student->id.'/account')
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/students/'.$student->id.'/account')
            ->assertUnprocessable();
    }

    public function test_login_akun_nonaktif_ditolak(): void
    {
        $user = $this->makeSiswa('siswa1', '10001');
        $user->student->update(['status' => 'Nonaktif']);

        $this->postJson('/api/login', [
            'identifier' => '10001',
            'password' => 'siswa12345',
        ])->assertUnprocessable();
    }

    public function test_ganti_password_mencabut_token_lain(): void
    {
        $user = $this->makeSiswa('siswa1', '10001');

        $lama = $user->createToken('lama')->plainTextToken;
        $baru = $user->createToken('baru')->plainTextToken;

        $response = $this->withToken($baru)->postJson('/api/change-password', [
            'current_password' => 'siswa12345',
            'password' => 'passwordbaru1',
            'password_confirmation' => 'passwordbaru1',
        ]);

        $response->assertOk();
        $this->assertSame(1, $user->fresh()->tokens()->count());

        // Guard auth di test cache user antar request; reset agar tiap request
        // mengevaluasi token seperti di produksi.
        $this->app['auth']->forgetGuards();

        // Token lama tidak lagi berlaku.
        $this->withToken($lama)->getJson('/api/me')->assertUnauthorized();
        // Token aktif tetap berlaku.
        $this->app['auth']->forgetGuards();
        $this->withToken($baru)->getJson('/api/me')->assertOk();
    }
}
