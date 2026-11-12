<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Satu berkas firmware ESP32 yang tersimpan di server. Admin mengunggah
 * file .bin, lalu perangkat menariknya lewat URL bertanda tangan (signed
 * URL) sehingga tidak perlu membawa device key di URL unduhan.
 */
class DeviceFirmware extends Model
{
    protected $table = 'device_firmwares';

    protected $fillable = [
        'version', 'original_name', 'path', 'size', 'checksum', 'notes', 'is_active', 'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Versi aktif dengan nomor tertinggi. Perbandingan memakai semver agar
     * 1.10.0 dianggap lebih baru daripada 1.9.0.
     */
    public static function latestActive(): ?self
    {
        /** @var Collection<int, self> $firmwares */
        $firmwares = self::query()->active()->get();

        return $firmwares
            ->sort(fn (self $a, self $b) => version_compare($b->version, $a->version))
            ->first();
    }

    public function hasFile(): bool
    {
        return $this->path !== '' && Storage::disk('local')->exists($this->path);
    }

    /**
     * URL unduhan bertanda tangan untuk perangkat. Masa berlaku dibatasi
     * supaya tautan lama tidak bisa dipakai selamanya.
     */
    public function downloadUrl(): string
    {
        $ttl = max(1, (int) config('presentech.firmware.signed_ttl_hours', 12));
        $expires = now()->addHours($ttl);
        $base = rtrim((string) config('presentech.firmware.url', ''), '/');

        if ($base === '') {
            return URL::temporarySignedRoute('firmware.download', $expires, ['firmware' => $this->id]);
        }

        // Tanda tangan Laravel ikut menyertakan host, jadi root URL diganti
        // sementara agar URL yang ditandatangani memakai alamat LAN yang
        // bisa dijangkau ESP32 (bukan "localhost" milik server).
        URL::forceRootUrl($base);

        try {
            return URL::temporarySignedRoute('firmware.download', $expires, ['firmware' => $this->id]);
        } finally {
            URL::forceRootUrl(null);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDevicePayload(): array
    {
        return [
            'version' => $this->version,
            'url' => $this->downloadUrl(),
            'size' => (int) $this->size,
            'sha256' => $this->checksum,
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $firmware): void {
            if ($firmware->path !== '') {
                Storage::disk('local')->delete($firmware->path);
            }
        });
    }
}
