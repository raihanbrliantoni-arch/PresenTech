<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SystemBackupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_otomatis_membuat_file_json(): void
    {
        Storage::fake('local');

        $this->artisan('system:backup')->assertSuccessful();

        $files = Storage::disk('local')->files('backups');

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.json', $files[0]);

        $payload = json_decode(Storage::disk('local')->get($files[0]), true);

        $this->assertSame('presentech-backup', $payload['app']);
        $this->assertArrayHasKey('tables', $payload);
    }

    public function test_backup_dry_run_tidak_menulis_file(): void
    {
        Storage::fake('local');

        $this->artisan('system:backup', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame([], Storage::disk('local')->files('backups'));
    }

    public function test_file_lebih_lama_dari_retensi_dihapus(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->makeDirectory('backups');

        $old = 'backups/presentech-backup-20250101-013000.json';
        $recent = 'backups/presentech-backup-20260101-013000.json';
        $disk->put($old, '{}');
        $disk->put($recent, '{}');

        touch($disk->path($old), Carbon::now()->subDays(31)->getTimestamp());
        touch($disk->path($recent), Carbon::now()->subDays(2)->getTimestamp());

        $this->artisan('system:backup')->assertSuccessful();

        $this->assertFalse($disk->exists($old));
        $this->assertTrue($disk->exists($recent));
    }

    public function test_opsi_keep_days_mengubah_retensi(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->makeDirectory('backups');

        $file = 'backups/presentech-backup-20250101-013000.json';
        $disk->put($file, '{}');
        touch($disk->path($file), Carbon::now()->subDays(5)->getTimestamp());

        $this->artisan('system:backup', ['--keep-days' => 3])->assertSuccessful();

        $this->assertFalse($disk->exists($file));
    }
}
