<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('devices:mark-offline {--seconds=90}')]
#[Description('Tandai perangkat Online yang tidak mengirim heartbeat sebagai Offline')]
class MarkDevicesOffline extends Command
{
    public function handle(): int
    {
        $threshold = now()->subSeconds(max(30, (int) $this->option('seconds')));

        $count = Device::query()
            ->where('status', 'Online')
            ->where(function ($query) use ($threshold) {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', $threshold);
            })
            ->update(['status' => 'Offline']);

        $this->info("{$count} perangkat ditandai Offline.");

        return self::SUCCESS;
    }
}
