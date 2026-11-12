<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Penanda bahwa scheduler Laravel benar-benar berjalan. Endpoint kesehatan
 * sistem membaca nilai ini; bila basi, Admin diberi tahu bahwa Alpa
 * otomatis, status offline, retensi log, dan backup tidak akan berjalan.
 */
#[Signature('system:heartbeat')]
#[Description('Catat waktu scheduler terakhir berjalan')]
class SystemHeartbeat extends Command
{
    public function handle(): int
    {
        Cache::put(
            'presentech.scheduler.last_run_at',
            now()->toIso8601String(),
            now()->addHours(6),
        );

        return self::SUCCESS;
    }
}
