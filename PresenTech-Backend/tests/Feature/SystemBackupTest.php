<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class SystemBackupTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_admin_bisa_mengunduh_dan_memulihkan_backup_database(): void
    {
        $admin = $this->makeAdmin();
        $class = SchoolClass::create([
            'name' => 'X RPL 1',
            'major' => 'RPL',
            'student_count' => 1,
        ]);
        Student::create([
            'class_id' => $class->id,
            'nis' => '30001',
            'name' => 'Siswa Backup',
            'status' => 'Aktif',
        ]);

        $backup = $this->actingAs($admin)->getJson('/api/system/backup')
            ->assertOk()
            ->assertJsonPath('app', 'presentech-backup')
            ->json();

        $this->assertNotEmpty($backup['tables']['students']);
        $this->assertNotEmpty($backup['tables']['users']);

        Student::query()->delete();

        $file = UploadedFile::fake()->createWithContent('backup.json', json_encode($backup));

        $this->actingAs($admin)->post('/api/system/restore', [
            'file' => $file,
            'confirm' => 'RESTORE',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('students', ['nis' => '30001', 'name' => 'Siswa Backup']);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_restore_menolak_file_yang_bukan_backup_presentech(): void
    {
        $admin = $this->makeAdmin();
        $file = UploadedFile::fake()->createWithContent('salah.json', json_encode(['app' => 'lain']));

        $this->actingAs($admin)->post('/api/system/restore', [
            'file' => $file,
            'confirm' => 'RESTORE',
        ])->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_non_admin_tidak_bisa_backup_atau_restore(): void
    {
        $siswa = $this->makeSiswa('siswabackup', '30002');

        $this->actingAs($siswa)->getJson('/api/system/backup')->assertForbidden();

        $file = UploadedFile::fake()->createWithContent('backup.json', json_encode(['app' => 'presentech-backup', 'tables' => []]));
        $this->actingAs($siswa)->post('/api/system/restore', ['file' => $file, 'confirm' => 'RESTORE'])->assertForbidden();
    }
}
