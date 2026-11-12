<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureDeviceKey;
use App\Models\Device;
use App\Models\DeviceFirmware;
use App\Support\Audit;
use App\Support\DeviceOta;
use App\Support\MqttDeviceBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Registry firmware OTA: unggah file .bin, aktif/nonaktifkan versi, kirim
 * pembaruan ke perangkat (HTTP heartbeat atau MQTT), dan terima laporan
 * hasil dari alat.
 *
 * File hanya bisa diunduh perangkat lewat URL bertanda tangan yang dibuat
 * saat permintaan OTA, jadi tidak ada endpoint unduhan terbuka.
 */
class FirmwareController extends Controller
{
    public function index(): JsonResponse
    {
        $firmwares = DeviceFirmware::query()
            ->with('uploader:id,name')
            ->latest()
            ->get()
            ->map(fn (DeviceFirmware $firmware) => $this->present($firmware))
            ->values();

        return response()->json([
            'success' => true,
            'firmwares' => $firmwares,
            'latest' => DeviceFirmware::latestActive()?->version,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => [
                'required', 'string', 'max:50',
                'regex:/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-]+)?$/',
                'unique:device_firmwares,version',
            ],
            'notes' => ['nullable', 'string', 'max:500'],
            'file' => ['required', 'file', 'max:16384', 'extensions:bin'],
        ]);

        $file = $request->file('file');
        $path = $file->store('firmware', 'local');
        $absolute = Storage::disk('local')->path($path);

        $firmware = DeviceFirmware::create([
            'version' => $data['version'],
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'size' => is_file($absolute) ? (int) filesize($absolute) : 0,
            'checksum' => is_file($absolute) ? (string) hash_file('sha256', $absolute) : '',
            'notes' => $data['notes'] ?? null,
            'is_active' => true,
            'uploaded_by' => $request->user()?->id,
        ]);

        Audit::log(
            $request,
            'system',
            'firmware.uploaded',
            'Firmware diunggah: '.$firmware->version,
            ['firmware_id' => $firmware->id, 'size' => $firmware->size],
        );

        return response()->json([
            'success' => true,
            'message' => 'Firmware '.$firmware->version.' berhasil diunggah.',
            'firmware' => $this->present($firmware->fresh('uploader')),
        ], 201);
    }

    public function update(Request $request, DeviceFirmware $firmware): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $firmware->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Firmware diperbarui.',
            'firmware' => $this->present($firmware->fresh('uploader')),
        ]);
    }

    public function destroy(Request $request, DeviceFirmware $firmware): JsonResponse
    {
        Device::query()
            ->where('ota_firmware_id', $firmware->id)
            ->update(['ota_firmware_id' => null, 'ota_requested_at' => null, 'ota_status' => null]);

        Audit::log(
            $request,
            'system',
            'firmware.deleted',
            'Firmware dihapus: '.$firmware->version,
            ['firmware_id' => $firmware->id],
        );

        $firmware->delete();

        return response()->json([
            'success' => true,
            'message' => 'Firmware dihapus.',
        ]);
    }

    /**
     * Minta satu perangkat memasang firmware (default: versi aktif terbaru).
     * Perintah langsung dipublikasikan lewat MQTT; tanpa MQTT alat akan
     * menerima detail OTA pada heartbeat HTTP berikutnya.
     */
    public function push(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate([
            'firmware_id' => ['nullable', 'integer', 'exists:device_firmwares,id'],
        ]);

        $firmware = isset($data['firmware_id'])
            ? DeviceFirmware::findOrFail($data['firmware_id'])
            : DeviceFirmware::latestActive();

        abort_if($firmware === null, 422, 'Belum ada firmware yang diunggah.');
        abort_if(! $firmware->is_active, 422, 'Firmware ini tidak aktif.');
        abort_if(! $firmware->hasFile(), 409, 'File firmware tidak ditemukan di server.');
        abort_if($device->status === 'Maintenance', 409, 'Perangkat sedang dalam mode Maintenance.');
        abort_if($device->firmware_version === $firmware->version, 409, 'Perangkat sudah memakai versi ini.');

        $device = DeviceOta::request($device, $firmware);
        $ota = DeviceOta::pending($device);

        MqttDeviceBus::publishCommand($device, 'ota', $ota ?? []);

        Audit::log(
            $request,
            'device',
            'device.ota_requested',
            'OTA diminta: '.$device->name.' → '.$firmware->version,
            ['device_id' => $device->id, 'firmware_id' => $firmware->id],
        );

        return response()->json([
            'success' => true,
            'message' => 'Perintah OTA dikirim. Alat akan mengunduh dan restart setelah selesai.',
            'device' => $device,
            'ota' => $ota,
        ]);
    }

    public function cancel(Request $request, Device $device): JsonResponse
    {
        $device = DeviceOta::cancel($device);

        MqttDeviceBus::publishCommand($device, 'cancel-ota');

        Audit::log(
            $request,
            'device',
            'device.ota_cancelled',
            'OTA dibatalkan: '.$device->name,
            ['device_id' => $device->id],
        );

        return response()->json([
            'success' => true,
            'message' => 'Permintaan OTA dibatalkan.',
            'device' => $device,
        ]);
    }

    /**
     * Laporan dari alat (pending/downloading/success/failed) via HTTP.
     */
    public function acknowledge(Request $request): JsonResponse
    {
        $device = $this->resolveDeviceFromKey($request);

        $data = $request->validate([
            'status' => ['required', 'string', 'max:30'],
            'version' => ['nullable', 'string', 'max:50'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $device = DeviceOta::applyReport($device, $data);

        return response()->json([
            'success' => true,
            'message' => 'Laporan OTA diterima.',
            'status' => $device->ota_status,
            'firmware_version' => $device->firmware_version,
        ]);
    }

    /**
     * Unduhan biner firmware. Hanya bisa diakses dengan URL bertanda tangan
     * yang dibuat saat OTA diminta (middleware "signed" di route).
     */
    public function download(DeviceFirmware $firmware): StreamedResponse
    {
        abort_unless($firmware->hasFile(), 404, 'File firmware tidak ditemukan.');

        return Storage::disk('local')->download(
            $firmware->path,
            'presentech-firmware-'.$firmware->version.'.bin',
            [
                'Content-Type' => 'application/octet-stream',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'X-Firmware-Version' => $firmware->version,
                'X-Firmware-Sha256' => $firmware->checksum,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DeviceFirmware $firmware): array
    {
        return [
            'id' => $firmware->id,
            'version' => $firmware->version,
            'original_name' => $firmware->original_name,
            'size' => $firmware->size,
            'checksum' => $firmware->checksum,
            'notes' => $firmware->notes,
            'is_active' => $firmware->is_active,
            'uploaded_by' => $firmware->uploader?->name,
            'created_at' => $firmware->created_at?->toIso8601String(),
            'file_available' => $firmware->hasFile(),
        ];
    }

    private function resolveDeviceFromKey(Request $request): Device
    {
        $device = $request->attributes->get(EnsureDeviceKey::REQUEST_ATTRIBUTE);

        abort_unless($device instanceof Device, 401, 'Device key tidak valid.');

        return $device;
    }
}
