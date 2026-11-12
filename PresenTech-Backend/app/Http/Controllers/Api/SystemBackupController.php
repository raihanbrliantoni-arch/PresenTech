<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\DatabaseBackup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Backup & restore database dari menu Pengaturan (khusus Admin).
 *
 * File backup berupa JSON berisi seluruh tabel aplikasi. Restore menimpa
 * data saat ini dan dijalankan dalam satu transaksi: bila ada tabel/kolom
 * yang tidak cocok, seluruh perubahan dibatalkan.
 */
class SystemBackupController extends Controller
{
    public function download(Request $request): JsonResponse
    {
        $payload = DatabaseBackup::payload();
        $tables = $payload['tables'];

        Audit::log($request, 'system', 'system.backup', 'Admin mengunduh backup database', [
            'tables' => array_map(fn (array $rows) => count($rows), $tables),
        ]);

        $filename = 'presentech-backup-'.now()->format('Ymd-His').'.json';

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function restore(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'confirm' => ['required', 'in:RESTORE'],
        ]);

        $raw = (string) file_get_contents($request->file('file')->getRealPath());
        $payload = json_decode($raw, true);

        abort_unless(is_array($payload) && ($payload['app'] ?? null) === 'presentech-backup', 422, 'File backup tidak valid.');
        abort_unless(is_array($payload['tables'] ?? null), 422, 'Struktur backup tidak lengkap.');

        $tables = $payload['tables'];

        foreach (DatabaseBackup::TABLES as $table) {
            abort_unless(isset($tables[$table]) && is_array($tables[$table]), 422, "Tabel {$table} tidak ada di file backup.");
        }

        $counts = [];

        try {
            DB::transaction(function () use ($tables, &$counts) {
                foreach (array_reverse(DatabaseBackup::TABLES) as $table) {
                    DB::table($table)->delete();
                }

                foreach (DatabaseBackup::TABLES as $table) {
                    $rows = $tables[$table];

                    foreach (array_chunk($rows, 200) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }

                    $counts[$table] = count($rows);
                }
            });
        } catch (Throwable $exception) {
            // Detail teknis hanya masuk log server; respons ke client dibuat
            // generik agar struktur database tidak bocor lewat pesan error.
            report($exception);

            return response()->json([
                'message' => 'Restore dibatalkan dan data lama tidak berubah. Periksa kembali file backup lalu coba lagi.',
            ], 422);
        }

        Audit::log($request, 'system', 'system.restore', 'Admin memulihkan backup database', ['counts' => $counts]);

        return response()->json([
            'success' => true,
            'message' => 'Backup berhasil dipulihkan.',
            'counts' => $counts,
        ]);
    }
}
