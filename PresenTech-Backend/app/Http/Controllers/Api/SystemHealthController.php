<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\DeviceFirmware;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Ringkasan kesehatan sistem untuk panel Monitoring: apakah scheduler
 * berjalan, kondisi perangkat, absensi hari ini, tap yang belum terverifikasi,
 * dan status backup otomatis terakhir.
 */
class SystemHealthController extends Controller
{
    public function index(): JsonResponse
    {
        $lastRun = Cache::get('presentech.scheduler.last_run_at');
        $lastRunAt = is_string($lastRun) ? Carbon::parse($lastRun) : null;

        $today = now()->toDateString();
        $statusCounts = Attendance::query()
            ->whereDate('attendance_date', $today)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'scheduler' => [
                'last_run_at' => $lastRunAt?->toIso8601String(),
                'stale' => $lastRunAt === null || $lastRunAt->lt(now()->subMinutes(3)),
            ],
            'devices' => [
                'total' => Device::count(),
                'online' => Device::where('status', 'Online')->count(),
                'offline' => Device::where('status', 'Offline')->count(),
                'maintenance' => Device::where('status', 'Maintenance')->count(),
                'stale' => Device::where('status', 'Online')->where('last_seen_at', '<', now()->subMinutes(10))->count(),
                'restart_pending' => Device::whereNotNull('restart_requested_at')->count(),
                'ota_pending' => Device::whereNotNull('ota_requested_at')->count(),
            ],
            'firmware' => [
                'latest' => DeviceFirmware::latestActive()?->version,
                'total' => DeviceFirmware::count(),
                'failed_7d' => Device::where('ota_status', 'failed')
                    ->where('ota_reported_at', '>=', now()->subDays(7))
                    ->count(),
            ],
            'attendance_today' => [
                'total' => Attendance::whereDate('attendance_date', $today)->count(),
                'hadir' => (int) ($statusCounts['Hadir'] ?? 0),
                'terlambat' => (int) ($statusCounts['Terlambat'] ?? 0),
                'izin' => (int) ($statusCounts['Izin'] ?? 0),
                'sakit' => (int) ($statusCounts['Sakit'] ?? 0),
                'alpa' => (int) ($statusCounts['Alpa'] ?? 0),
                'auto_alpa' => Attendance::whereDate('attendance_date', $today)->where('auto_alpa', true)->count(),
            ],
            'unverified_taps_7d' => Attendance::where('created_at', '>=', now()->subDays(7))
                ->where('notes', 'like', '%belum terverifikasi%')
                ->count(),
            'logs' => [
                'total' => SystemLog::count(),
                'last_24h' => SystemLog::where('created_at', '>=', now()->subDay())->count(),
            ],
            'backups' => $this->backups(),
        ]);
    }

    /**
     * @return array{retention_days: int, count: int, latest: array{file: string, size: int, modified_at: string}|null}
     */
    private function backups(): array
    {
        $disk = Storage::disk('local');

        $files = collect($disk->files('backups'))
            ->filter(fn (string $file) => str_starts_with(basename($file), 'presentech-backup-') && str_ends_with($file, '.json'))
            ->map(fn (string $file) => [
                'file' => basename($file),
                'size' => $disk->size($file),
                'modified_at' => Carbon::createFromTimestamp($disk->lastModified($file))->toIso8601String(),
            ])
            ->sortByDesc('modified_at')
            ->values();

        return [
            'retention_days' => (int) config('presentech.backup.retention_days', 30),
            'count' => $files->count(),
            'latest' => $files->first(),
        ];
    }
}
