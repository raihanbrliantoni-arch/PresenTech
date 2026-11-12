<?php

namespace Tests\Feature;

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_status_baca_broadcast_terpisah_antar_user(): void
    {
        $siswaA = $this->makeSiswa('siswaa', '10001');
        $siswaB = $this->makeSiswa('siswab', '10002');

        Notification::create([
            'recipient_role' => 'siswa',
            'type' => 'system',
            'title' => 'Pengumuman Sekolah',
            'message' => 'Ujian dimulai Senin.',
            'expires_at' => now()->addHours(24),
        ]);

        // Siswa A menandai semua dibaca.
        $this->actingAs($siswaA)->postJson('/api/notifications/read-all')->assertOk();

        // Notifikasi tidak terhapus dan Siswa B masih melihat belum dibaca.
        $this->assertDatabaseCount('notifications', 1);

        $responseB = $this->actingAs($siswaB)->getJson('/api/notifications');
        $responseB->assertOk();
        $this->assertFalse($responseB->json('data.0.is_read'));

        // Siswa A melihat sudah dibaca.
        $responseA = $this->actingAs($siswaA)->getJson('/api/notifications');
        $responseA->assertOk();
        $this->assertTrue($responseA->json('data.0.is_read'));

        // Pivot tercatat hanya untuk Siswa A.
        $this->assertDatabaseHas('notification_reads', [
            'user_id' => $siswaA->id,
        ]);
        $this->assertDatabaseMissing('notification_reads', [
            'user_id' => $siswaB->id,
        ]);
    }

    public function test_user_lain_tidak_bisa_menandai_notifikasi_orang_lain(): void
    {
        $siswa = $this->makeSiswa('siswa1', '10001');
        $guru = $this->makeGuru('guru1');

        $notification = Notification::create([
            'recipient_user_id' => $siswa->id,
            'recipient_role' => 'siswa',
            'type' => 'system',
            'title' => 'Pesan Pribadi',
            'message' => 'Hanya untuk siswa ini.',
            'expires_at' => now()->addHours(24),
        ]);

        // Guru (role berbeda) tidak boleh menandai dibaca / menghapus.
        $this->actingAs($guru)
            ->postJson('/api/notifications/'.$notification->id.'/read')
            ->assertForbidden();
        $this->actingAs($guru)
            ->deleteJson('/api/notifications/'.$notification->id)
            ->assertForbidden();

        // Pemilik boleh.
        $this->actingAs($siswa)
            ->postJson('/api/notifications/'.$notification->id.'/read')
            ->assertOk();
        $this->assertTrue($notification->fresh()->is_read);
    }

    public function test_notifikasi_broadcast_hanya_dihapus_admin(): void
    {
        $siswa = $this->makeSiswa('siswa1', '10001');
        $admin = $this->makeAdmin();

        $notification = Notification::create([
            'recipient_role' => 'siswa',
            'type' => 'system',
            'title' => 'Pengumuman',
            'message' => 'Libur nasional.',
            'expires_at' => now()->addHours(24),
        ]);

        $this->actingAs($siswa)
            ->deleteJson('/api/notifications/'.$notification->id)
            ->assertForbidden();
        $this->actingAs($admin)
            ->deleteJson('/api/notifications/'.$notification->id)
            ->assertOk();
    }

    public function test_buat_notifikasi_hanya_admin_dan_pengirim_tidak_bisa_dipalsukan(): void
    {
        $siswa = $this->makeSiswa('siswa1', '10001');
        $admin = $this->makeAdmin();

        $payload = [
            'recipient_role' => 'siswa',
            'title' => 'Info Absensi',
            'message' => 'Jangan lupa absen pagi.',
        ];

        $this->actingAs($siswa)->postJson('/api/notifications', $payload)->assertForbidden();

        $response = $this->actingAs($admin)
            ->postJson('/api/notifications', $payload + ['sender_user_id' => $siswa->id]);
        $response->assertCreated();

        // Pengirim dipaksa dari sesi admin, bukan input client.
        $this->assertSame($admin->id, $response->json('sender_user_id'));
    }

    public function test_scoping_notifikasi_siswa_hanya_kelas_dan_nis_sendiri(): void
    {
        $siswaLain = $this->makeSiswa('siswax', '10005');
        $siswa = $this->makeSiswa('siswa1', '10001');

        Notification::create([
            'recipient_role' => 'siswa',
            'recipient_class' => 'XI RPL 2',
            'title' => 'Kelas Lain',
            'message' => 'Bukan untuk siswa ini.',
            'expires_at' => now()->addHours(24),
        ]);
        Notification::create([
            'recipient_role' => 'siswa',
            'recipient_nis' => '10005',
            'title' => 'NIS Orang Lain',
            'message' => 'Bukan untuk siswa ini.',
            'expires_at' => now()->addHours(24),
        ]);
        Notification::create([
            'recipient_role' => 'siswa',
            'title' => 'Semua Siswa',
            'message' => 'Untuk semua siswa.',
            'expires_at' => now()->addHours(24),
        ]);

        $response = $this->actingAs($siswa)->getJson('/api/notifications');
        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertNotContains('Kelas Lain', $titles);
        $this->assertNotContains('NIS Orang Lain', $titles);
        $this->assertContains('Semua Siswa', $titles);
    }
}
