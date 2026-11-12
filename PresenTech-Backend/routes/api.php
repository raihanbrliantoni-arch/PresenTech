<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceCorrectionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\FirmwareController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RfidCardController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\SystemBackupController;
use App\Http\Controllers\Api\SystemHealthController;
use App\Http\Controllers\Api\SystemLogController;
use App\Http\Controllers\Api\TeacherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Publik (rate limited)
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');

Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'service' => 'PresenTech API',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Alur lupa password: pengajuan sekaligus reset (NIS/NIP menjadi password),
// plus cek status — user yang lupa password tidak punya sesi login.
Route::post('/password-reset/requests', [PasswordResetController::class, 'request'])->middleware('throttle:auth');
Route::get('/password-reset/requests', [PasswordResetController::class, 'status'])->middleware('throttle:auth');

/*
|--------------------------------------------------------------------------
| Perangkat fisik (ESP32) — wajib header X-Device-Key
|
| Semua endpoint di grup ini lewat middleware device.key, termasuk
| `/devices/{device}/heartbeat` yang sebelumnya bisa dipanggil dengan sesi
| Admin saja (bindings {device} tidak lagi dianggap sah tanpa key).
|
| `/attendance` sengaja TIDAK di sini: endpoint yang sama dipakai absensi
| manual Admin/Guru dengan Bearer token (dicek di controller).
|--------------------------------------------------------------------------
*/

Route::middleware(['device.key', 'throttle:device'])->group(function () {
    Route::post('/device/attendance/batch', [AttendanceController::class, 'storeBatch']);
    Route::post('/device/heartbeat', [DeviceController::class, 'heartbeatByKey']);
    Route::post('/device/restart-ack', [DeviceController::class, 'acknowledgeRestart']);
    Route::post('/device/wifi-ack', [DeviceController::class, 'acknowledgeWifi']);
    Route::post('/device/scan-result', [DeviceController::class, 'scanResult']);
    Route::post('/device/ota-ack', [FirmwareController::class, 'acknowledge']);
    Route::post('/devices/{device}/heartbeat', [DeviceController::class, 'heartbeat']);
});

Route::post('/attendance', [AttendanceController::class, 'store'])->middleware('throttle:device');

// Unduhan firmware memakai URL bertanda tangan (dibuat saat OTA diminta),
// jadi tidak perlu sesi login maupun device key di URL.
Route::get('/firmware/{firmware}/download', [FirmwareController::class, 'download'])
    ->middleware('signed')
    ->name('firmware.download');

/*
|--------------------------------------------------------------------------
| Terverifikasi — semua role (admin, guru, siswa)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'active', 'password.changed'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/me', [AuthController::class, 'updateProfile']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    Route::get('/settings', [SettingController::class, 'show']);

    // Notifikasi: scoping penerima + status baca per user dicek di controller.
    Route::apiResource('notifications', NotificationController::class)->only(['index', 'show', 'destroy']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    // Jadwal & absensi: semua role boleh membaca (siswa dibatasi ke datanya sendiri di controller).
    Route::get('/schedules', [ScheduleController::class, 'index']);
    Route::get('/schedules/{schedule}', [ScheduleController::class, 'show']);
    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::get('/attendance/{attendance}', [AttendanceController::class, 'show']);

    // Status hari libur dipakai semua dashboard agar tidak menampilkan
    // kehadiran 0% pada tanggal libur.
    Route::get('/holidays/today', [HolidayController::class, 'today']);

    // Koreksi absensi: pengajuan (siswa hanya untuk dirinya), status baca/list dibatasi di controller.
    Route::get('/attendance-corrections', [AttendanceCorrectionController::class, 'index']);
    Route::get('/attendance-corrections/{attendanceCorrection}', [AttendanceCorrectionController::class, 'show']);
    Route::post('/attendance-corrections', [AttendanceCorrectionController::class, 'store']);
    Route::delete('/attendance-corrections/{attendanceCorrection}', [AttendanceCorrectionController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Guru & Admin
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'active', 'password.changed', 'role:admin,guru'])->group(function () {
    Route::get('/students', [StudentController::class, 'index']);
    Route::get('/students/{student}', [StudentController::class, 'show']);
    Route::get('/classes', [ClassController::class, 'index']);
    Route::get('/holidays', [HolidayController::class, 'index']);
    Route::get('/reports/attendance', [ReportController::class, 'attendance']);
});

/*
|--------------------------------------------------------------------------
| Admin
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'active', 'password.changed', 'role:admin'])->group(function () {

    // Atur nomor absen otomatis (urut nama) untuk semua/kelas tertentu.
    Route::post('/students/auto-number', [StudentController::class, 'autoNumber']);
    Route::apiResource('students', StudentController::class)->only(['store', 'update', 'destroy']);
    Route::post('/students/{student}/account', [AuthController::class, 'createStudentAccount']);
    Route::apiResource('teachers', TeacherController::class);
    Route::post('/teachers/{teacher}/account', [AuthController::class, 'createTeacherAccount']);
    Route::post('/teachers/{teacher}/promote', [TeacherController::class, 'promote']);
    Route::post('/teachers/{teacher}/demote', [TeacherController::class, 'demote']);
    Route::apiResource('classes', ClassController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('schedules', ScheduleController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('rfid-cards', RfidCardController::class);
    Route::apiResource('attendance', AttendanceController::class)->only(['update', 'destroy']);
    Route::put('/attendance-corrections/{attendanceCorrection}', [AttendanceCorrectionController::class, 'update']);
    Route::apiResource('system-logs', SystemLogController::class)->only(['index', 'store', 'destroy']);
    Route::apiResource('devices', DeviceController::class);
    Route::post('/devices/{device}/restart', [DeviceController::class, 'restart']);
    Route::post('/devices/{device}/rotate-key', [DeviceController::class, 'rotateKey']);
    Route::post('/devices/{device}/wifi', [DeviceController::class, 'setWifi']);
    Route::post('/devices/{device}/scan', [DeviceController::class, 'requestScan']);
    Route::get('/devices/{device}/scan', [DeviceController::class, 'scanStatus']);
    Route::delete('/devices/{device}/scan', [DeviceController::class, 'cancelScan']);
    Route::get('/devices/{device}/preview', [DeviceController::class, 'preview']);

    // Firmware OTA: unggah versi, aktif/nonaktifkan, dan kirim ke perangkat.
    Route::get('/firmwares', [FirmwareController::class, 'index']);
    Route::post('/firmwares', [FirmwareController::class, 'store']);
    Route::put('/firmwares/{firmware}', [FirmwareController::class, 'update']);
    Route::delete('/firmwares/{firmware}', [FirmwareController::class, 'destroy']);
    Route::post('/devices/{device}/ota', [FirmwareController::class, 'push']);
    Route::delete('/devices/{device}/ota', [FirmwareController::class, 'cancel']);

    Route::post('/notifications', [NotificationController::class, 'store']);
    Route::put('/settings', [SettingController::class, 'update']);

    // Kalender hari libur (hanya Admin yang boleh mengubah).
    Route::post('/holidays', [HolidayController::class, 'store']);
    Route::put('/holidays/{holiday}', [HolidayController::class, 'update']);
    Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy']);

    Route::get('/admin/password-reset/requests', [PasswordResetController::class, 'adminIndex']);
    Route::post('/admin/password-reset/requests/{reset}/approve', [PasswordResetController::class, 'approve']);
    Route::post('/admin/password-reset/requests/{reset}/reject', [PasswordResetController::class, 'reject']);
    Route::post('/admin/users/{user}/set-password', [PasswordResetController::class, 'adminSetPassword']);

    // Backup & restore database (khusus Admin).
    Route::get('/system/backup', [SystemBackupController::class, 'download']);
    Route::post('/system/restore', [SystemBackupController::class, 'restore']);

    // Ringkasan kesehatan sistem untuk panel Monitoring Admin.
    Route::get('/system/health', [SystemHealthController::class, 'index']);
});
