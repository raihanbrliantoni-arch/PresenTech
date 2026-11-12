<?php

namespace App\Console\Commands;

use App\Models\SystemLog;
use App\Support\DatabaseBackup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Menyimpan backup database ke disk server setiap hari dan menghapus file
 * yang lebih lama dari masa retensi. File disimpan di folder backups pada
 * disk "local" (storage/app/private/backups).
 */
#[Signature('system:backup {--keep-days= : Jumlah hari retensi file (default dari config)} {--dry-run : Tampilkan rencana tanpa menulis file}')]
#[Description('Buat backup database otomatis dan bersihkan file lama')]
class BackupDatabase extends Command
{
    public function handle(): int
    {
        $keepDays = max(1, (int) ($this->option('keep-days') ?: config('presentech.backup.retention_days', 30)));
        $disk = Storage::disk('local');
        $filename = 'backups/presentech-backup-'.now()->format('Ymd-His').'.json';
        $payload = DatabaseBackup::payload();
        $rows = array_sum(array_map('count', $payload['tables']));

        if ($this->option('dry-run')) {
            $this->info('Akan membuat '.$filename." ({$rows} baris) dan menyimpan file {$keepDays} hari terakhir.");

            return self::SUCCESS;
        }

        $disk->put($filename, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $deleted = $this->prune($disk, $keepDays);

        SystemLog::create([
            'type' => 'system',
            'action' => 'system.backup.auto',
            'message' => 'Backup otomatis dibuat: '.basename($filename),
            'metadata' => ['file' => basename($filename), 'rows' => $rows, 'deleted_files' => $deleted],
        ]);

        $this->info('Backup dibuat: '.basename($filename)." ({$rows} baris). {$deleted} file lama dihapus.");

        return self::SUCCESS;
    }

    private function prune(Filesystem $disk, int $keepDays): int
    {
        $threshold = Carbon::now()->subDays($keepDays)->getTimestamp();
        $deleted = 0;

        foreach ($disk->files('backups') as $file) {
            $name = basename($file);

            if (! str_starts_with($name, 'presentech-backup-') || ! str_ends_with($name, '.json')) {
                continue;
            }

            if ($disk->lastModified($file) < $threshold) {
                $disk->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
