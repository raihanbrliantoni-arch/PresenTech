<?php

namespace App\Console\Commands;

use App\Models\SystemLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Log sistem hanya disimpan selama masa retensi (default 30 hari) agar
 * tabel tidak terus membesar. Dijalankan otomatis oleh scheduler harian;
 * opsi --days dipakai untuk menguji atau memperpendek retensi.
 */
#[Signature('logs:prune {--days= : Jumlah hari retensi (default dari config presentech.logs.retention_days)}')]
#[Description('Hapus log sistem yang lebih lama dari masa retensi')]
class PruneSystemLogs extends Command
{
    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('presentech.logs.retention_days', 30)));

        $deleted = SystemLog::query()
            ->where('created_at', '<', Carbon::now()->subDays($days))
            ->delete();

        $this->info("{$deleted} log lebih lama dari {$days} hari dihapus.");

        return self::SUCCESS;
    }
}
