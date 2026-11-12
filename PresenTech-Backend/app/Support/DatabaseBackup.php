<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Sumber tunggal daftar tabel dan isi backup database. Dipakai oleh endpoint
 * unduh backup (Admin) dan perintah backup otomatis agar keduanya selalu
 * menghasilkan struktur file yang sama.
 */
final class DatabaseBackup
{
    /**
     * Urutan ini menghormati foreign key: induk lebih dulu saat insert,
     * anak lebih dulu saat delete (dibalik).
     *
     * @var array<int, string>
     */
    public const TABLES = [
        'users',
        'school_classes',
        'teachers',
        'students',
        'rfid_cards',
        'devices',
        'schedules',
        'attendances',
        'attendance_corrections',
        'notifications',
        'notification_reads',
        'password_reset_requests',
        'system_logs',
        'settings',
    ];

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function tables(): array
    {
        $tables = [];

        foreach (self::TABLES as $table) {
            $tables[$table] = DB::table($table)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        return $tables;
    }

    /**
     * @return array{app: string, version: int, generated_at: string, tables: array<string, array<int, array<string, mixed>>>}
     */
    public static function payload(): array
    {
        return [
            'app' => 'presentech-backup',
            'version' => 1,
            'generated_at' => now()->toIso8601String(),
            'tables' => self::tables(),
        ];
    }
}
