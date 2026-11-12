<?php

namespace App\Models;

use App\Support\DeviceDisplay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

class Device extends Model
{
    protected $fillable = [
        'device_id', 'name', 'display_name', 'school_label', 'theme_color',
        'location', 'mac_address', 'ip_address', 'firmware_version', 'boot_id', 'booted_at', 'firmware_hash',
        'wifi_ssid', 'wifi_password', 'wifi_token', 'wifi_requested_at',
        'connection_type', 'status', 'last_seen_at', 'last_restart_at', 'restart_requested_at',
        'scan_requested_at', 'scanned_uid', 'scanned_at', 'offline_alerted_at',
        'ota_firmware_id', 'ota_requested_at', 'ota_status', 'ota_reported_at', 'ota_message',
    ];

    // device_key diisi sistem (hash SHA-256) dan tidak boleh di-mass-assign / tampil.
    // wifi_password & wifi_token hanya untuk alat (lewat heartbeat), tidak untuk dashboard.
    protected $hidden = ['device_key', 'wifi_password', 'wifi_token'];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_restart_at' => 'datetime',
        'restart_requested_at' => 'datetime',
        'scan_requested_at' => 'datetime',
        'scanned_at' => 'datetime',
        'offline_alerted_at' => 'datetime',
        'ota_requested_at' => 'datetime',
        'ota_reported_at' => 'datetime',
        'booted_at' => 'datetime',
        'wifi_requested_at' => 'datetime',
        'wifi_password' => 'encrypted',
    ];

    /**
     * Nama yang aman ditampilkan di layar alat: nama kustom bila diisi,
     * selain itu nama perangkat yang sudah dipendekkan.
     */
    public function displayLabel(): string
    {
        $label = trim((string) ($this->display_name ?: $this->name));

        return DeviceDisplay::shorten($label, 20, 'Alat');
    }

    /**
     * Label sekolah yang tampil sebagai baris kedua di layar alat.
     */
    public function schoolLabel(): string
    {
        return DeviceDisplay::shorten((string) ($this->school_label ?: config('app.name', 'PresenTech')), 24, 'PresenTech');
    }

    /**
     * Metadata log/OTA berisi detail internal (mis. nama file, id perangkat)
     * yang tidak perlu bocor ke dashboard; kuncinya memakai nama relasi JSON
     * (snake_case), bukan nama method.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        foreach (['system_logs', 'attendances'] as $relation) {
            if (isset($array[$relation]) && is_array($array[$relation])) {
                $array[$relation] = array_map(
                    static fn ($row) => is_array($row) ? Arr::except($row, ['metadata']) : $row,
                    $array[$relation],
                );
            }
        }

        return $array;
    }

    public function otaFirmware(): BelongsTo
    {
        return $this->belongsTo(DeviceFirmware::class, 'ota_firmware_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function systemLogs(): HasMany
    {
        return $this->hasMany(SystemLog::class);
    }
}
