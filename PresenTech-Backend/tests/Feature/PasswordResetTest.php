<?php

namespace Tests\Feature;

use App\Models\PasswordResetRequest;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_pengajuan_reset_tidak_langsung_mengubah_password(): void
    {
        $siswa = $this->makeSiswa('siswa1', '10001');
        $siswa->createToken('sesi-lama');

        $response = $this->postJson('/api/password-reset/requests', [
            'identifier' => '10001',
            'role' => 'siswa',
        ]);

        $response->assertCreated()
            ->assertJsonPath('request.status', 'PENDING');

        $reset = $response->json('request');
        $this->assertNotEmpty($response->json('token'));
        $this->assertArrayNotHasKey('user_id', $reset);
        $this->assertArrayNotHasKey('role', $reset);
        $this->assertArrayNotHasKey('review_note', $reset);
        $this->assertArrayNotHasKey('initial_password', $response->json());

        // Password TIDAK boleh berubah sebelum Admin menyetujui.
        $siswa->refresh();
        $this->assertTrue(Hash::check('siswa12345', $siswa->password));
        $this->assertFalse($siswa->must_change_password);
        // Sesi lama tetap hidup karena belum ada reset yang disetujui.
        $this->assertSame(1, $siswa->tokens()->count());
    }

    public function test_pengajuan_reset_guru_memakai_nip(): void
    {
        $guru = $this->makeGuru('guru1');
        $nip = $guru->teacher->nip;

        $this->postJson('/api/password-reset/requests', [
            'identifier' => $nip,
            'role' => 'guru',
        ])->assertCreated()
            ->assertJsonPath('request.status', 'PENDING')
            ->assertJsonPath('request.identifier', $nip);

        // Password lama tetap berlaku sampai Admin menyetujui.
        $this->postJson('/api/login', [
            'identifier' => $nip,
            'password' => 'guru12345',
        ])->assertOk();
    }

    public function test_status_permintaan_memerlukan_token_klaim(): void
    {
        $this->makeSiswa('siswa1', '10001');

        $created = $this->postJson('/api/password-reset/requests', [
            'identifier' => '10001',
            'role' => 'siswa',
        ])->assertCreated();

        // Tanpa token / token salah: data tidak dibocorkan.
        $this->getJson('/api/password-reset/requests?request_id='.$created->json('request.id'))
            ->assertUnprocessable();

        $this->getJson('/api/password-reset/requests?request_id='.$created->json('request.id').'&token=salah')
            ->assertOk()
            ->assertJsonPath('request', null);

        // Id asal juga tidak membocorkan apa pun.
        $this->getJson('/api/password-reset/requests?request_id=99999&token=salah')
            ->assertOk()
            ->assertJsonPath('request', null);

        $status = $this->getJson('/api/password-reset/requests?request_id='.$created->json('request.id').'&token='.$created->json('token'))
            ->assertOk()
            ->assertJsonPath('request.status', 'PENDING');

        $this->assertArrayNotHasKey('user_id', $status->json('request'));
        $this->assertArrayNotHasKey('review_note', $status->json('request'));
    }

    public function test_pengajuan_ulang_memakai_baris_lama_dan_mengganti_token(): void
    {
        $this->makeSiswa('siswa1', '10001');

        $pertama = $this->postJson('/api/password-reset/requests', [
            'identifier' => '10001',
            'role' => 'siswa',
        ])->assertCreated();

        $kedua = $this->postJson('/api/password-reset/requests', [
            'identifier' => '10001',
            'role' => 'siswa',
        ])->assertCreated();

        // Baris lama dipakai ulang, tidak menumpuk antrean di panel Admin.
        $this->assertSame($pertama->json('request.id'), $kedua->json('request.id'));
        $this->assertSame(1, PasswordResetRequest::count());

        // Token lama otomatis tidak berlaku.
        $this->getJson('/api/password-reset/requests?request_id='.$kedua->json('request.id').'&token='.$pertama->json('token'))
            ->assertOk()
            ->assertJsonPath('request', null);

        $this->getJson('/api/password-reset/requests?request_id='.$kedua->json('request.id').'&token='.$kedua->json('token'))
            ->assertOk()
            ->assertJsonPath('request.status', 'PENDING');
    }

    public function test_pengajuan_reset_untuk_data_nonaktif_ditolak(): void
    {
        $siswa = $this->makeSiswa('siswa1', '10001');
        $siswa->student->update(['status' => 'Nonaktif']);

        $this->postJson('/api/password-reset/requests', [
            'identifier' => '10001',
            'role' => 'siswa',
        ])->assertUnprocessable();

        // Password tidak boleh berubah saat pengajuan ditolak.
        $this->assertTrue(Hash::check('siswa12345', $siswa->fresh()->password));
    }

    public function test_pengajuan_reset_untuk_data_tanpa_akun_login_ditolak(): void
    {
        Student::create([
            'nis' => '10009',
            'name' => 'Tanpa Akun',
            'status' => 'Aktif',
            'class_id' => null,
        ]);

        $this->postJson('/api/password-reset/requests', [
            'identifier' => '10009',
            'role' => 'siswa',
        ])->assertUnprocessable();
    }

    public function test_admin_menyetujui_permintaan_lalu_password_wajib_diganti(): void
    {
        $siswa = $this->makeSiswa('siswa1', '10001');
        $admin = $this->makeAdmin();
        $siswa->createToken('sesi-lama');

        $created = $this->postJson('/api/password-reset/requests', [
            'identifier' => '10001',
            'role' => 'siswa',
        ])->assertCreated();

        $reset = PasswordResetRequest::findOrFail($created->json('request.id'));

        // Hanya Admin yang boleh menyetujui.
        $this->actingAs($siswa)
            ->postJson('/api/admin/password-reset/requests/'.$reset->id.'/approve')
            ->assertForbidden();

        $approved = $this->actingAs($admin)
            ->postJson('/api/admin/password-reset/requests/'.$reset->id.'/approve')
            ->assertOk();

        $this->assertSame('10001', $approved->json('initial_password'));
        $this->assertSame('COMPLETED', $reset->fresh()->status);

        $siswa->refresh();
        $this->assertTrue(Hash::check('10001', $siswa->password));
        $this->assertTrue($siswa->must_change_password);
        // Sesi lama dicabut agar akun tidak terbawa di perangkat lain.
        $this->assertSame(0, $siswa->tokens()->count());

        // Password sementara bisa dipakai login, tetapi wajib diganti.
        $login = $this->postJson('/api/login', [
            'identifier' => '10001',
            'password' => '10001',
        ])->assertOk();

        $this->assertTrue($login->json('user.must_change_password'));
    }

    public function test_admin_menolak_permintaan_tidak_mengubah_password(): void
    {
        $siswa = $this->makeSiswa('siswa1', '10001');
        $admin = $this->makeAdmin();

        $created = $this->postJson('/api/password-reset/requests', [
            'identifier' => '10001',
            'role' => 'siswa',
        ])->assertCreated();

        $reset = PasswordResetRequest::findOrFail($created->json('request.id'));

        $this->actingAs($admin)
            ->postJson('/api/admin/password-reset/requests/'.$reset->id.'/reject', [
                'review_note' => 'Identitas belum terverifikasi.',
            ])
            ->assertOk();

        $this->assertSame('REJECTED', $reset->fresh()->status);
        $this->assertTrue(Hash::check('siswa12345', $siswa->fresh()->password));
    }

    public function test_permintaan_kedaluwarsa_tidak_bisa_disetujui(): void
    {
        $siswa = $this->makeSiswa('siswa1', '10001');
        $admin = $this->makeAdmin();

        $reset = PasswordResetRequest::create([
            'user_id' => $siswa->id,
            'identifier' => '10001',
            'role' => 'siswa',
            'status' => 'PENDING',
            'expires_at' => now()->subHour(),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/password-reset/requests/'.$reset->id.'/approve')
            ->assertUnprocessable();

        $this->assertSame('REJECTED', $reset->fresh()->status);
        $this->assertTrue(Hash::check('siswa12345', $siswa->fresh()->password));
    }

    public function test_admin_bisa_reset_password_langsung_menjadi_nis(): void
    {
        $siswa = $this->makeSiswa('siswa1', '10001');
        $admin = $this->makeAdmin();
        $siswa->createToken('sesi-lama');

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/users/'.$siswa->id.'/set-password')
            ->assertOk();

        $this->assertSame('siswa1', $response->json('initial_password'));
        $this->assertTrue($response->json('must_change_password'));
        $this->assertTrue($siswa->fresh()->must_change_password);
        $this->assertSame(0, $siswa->fresh()->tokens()->count());

        // Siswa tidak bisa memakai endpoint admin ini.
        $this->actingAs($siswa)
            ->postJson('/api/admin/users/'.$siswa->id.'/set-password')
            ->assertForbidden();
    }
}
