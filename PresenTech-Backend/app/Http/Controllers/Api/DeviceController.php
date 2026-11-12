<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureDeviceKey;
use App\Models\Device;
use App\Models\DeviceFirmware;
use App\Support\Audit;
use App\Support\DeviceOta;
use App\Support\DeviceRuntime;
use App\Support\DeviceStatus;
use App\Support\MqttDeviceBus;
use App\Support\RfidUid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    public const SCAN_TTL_SECONDS = 60;

    /**
     * Daftar fitur yang dikenal server. Alat firmware baru bisa mengecek
     * `capabilities` pada balasan heartbeat dan hanya memakai field yang
     * didukung; firmware lama mengabaikan field ini sepenuhnya.
     *
     * @var list<string>
     */
    public const DEVICE_CAPABILITIES = [
        'display_text', 'display_theme', 'boot_id', 'firmware_hash', 'attendance_window', 'ota', 'scan', 'offline_queue', 'wifi_config',
    ];

    private const SCAN_RESULT_TTL_SECONDS = 120;

    public function index(Request $request)
    {
        $query = Device::query();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('device_id', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        // Status live dihitung dari last_seen_at agar dashboard tidak
        // menunggu scheduler devices:mark-offline untuk memperbarui tampilan.
        $devices = $query->latest()->paginate(min(max((int) $request->input('per_page', 20), 1), 1000));
        $latestFirmware = DeviceFirmware::latestActive();

        $devices->getCollection()->each(function (Device $device) use ($latestFirmware) {
            $device->setAttribute('status', DeviceStatus::live($device));
            $device->setAttribute('firmware_latest', $latestFirmware?->version);
            $device->setAttribute('firmware_update_available', $latestFirmware !== null
                && ($device->firmware_version === null || version_compare((string) $device->firmware_version, $latestFirmware->version, '<')));
        });

        return response()->json($devices);
    }

    public function show(Device $device)
    {
        return response()->json($device->load(['attendances', 'systemLogs']));
    }

    /**
     * Pratinjau layar alat untuk dashboard Admin: teks yang benar-benar akan
     * dikirim ke ESP32 (nama sudah dipendekkan), tanpa perlu menunggu
     * perangkat online.
     */
    public function preview(Device $device)
    {
        return response()->json([
            'success' => true,
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'display_name' => $device->display_name,
                'theme_color' => $device->theme_color,
            ],
            'display' => DeviceRuntime::display($device),
            'window' => DeviceRuntime::attendanceWindow(),
            'capabilities' => self::DEVICE_CAPABILITIES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'device_id' => ['nullable', 'string', 'max:100', Rule::unique('devices', 'device_id')],
            'name' => ['required', 'string', 'max:150'],
            'display_name' => ['nullable', 'string', 'max:32'],
            'school_label' => ['nullable', 'string', 'max:32'],
            'theme_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'location' => ['nullable', 'string', 'max:150'],
            'mac_address' => ['nullable', 'string', 'max:32', 'unique:devices,mac_address'],
            'ip_address' => ['nullable', 'ip'],
            'firmware_version' => ['nullable', 'string', 'max:50'],
            'connection_type' => ['nullable', Rule::in(['WiFi', 'Ethernet', 'Unknown'])],
            'status' => ['nullable', Rule::in(['Online', 'Offline', 'Maintenance'])],
        ]);

        // Device ID dibuat otomatis dari waktu pembuatan bila Admin tidak
        // mengisinya, supaya penamaan perangkat tidak pernah bentrok.
        $data['device_id'] = filled($data['device_id'] ?? null)
            ? trim($data['device_id'])
            : 'DEV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));

        $device = Device::create($data);
        $device->device_key = hash('sha256', $deviceKey = 'ptd_'.Str::random(32));
        $device->save();
        Audit::log($request, 'device', 'device.created', 'Perangkat ditambahkan: '.$device->name, ['device_id' => $device->id]);

        // device_key ditampilkan sekali saja saat pembuatan; selanjutnya
        // hanya tersimpan sebagai hash dan tidak pernah ditampilkan lagi.
        return response()->json($device->setAttribute('device_key_plain', $deviceKey), 201);
    }

    public function update(Request $request, Device $device)
    {
        $data = $request->validate([
            'device_id' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('devices', 'device_id')->ignore($device->id)],
            'name' => ['sometimes', 'string', 'max:150'],
            'display_name' => ['nullable', 'string', 'max:32'],
            'school_label' => ['nullable', 'string', 'max:32'],
            'theme_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'location' => ['nullable', 'string', 'max:150'],
            'mac_address' => ['nullable', 'string', 'max:32', Rule::unique('devices', 'mac_address')->ignore($device->id)],
            'ip_address' => ['nullable', 'ip'],
            'firmware_version' => ['nullable', 'string', 'max:50'],
            'connection_type' => ['sometimes', Rule::in(['WiFi', 'Ethernet', 'Unknown'])],
            'status' => ['sometimes', Rule::in(['Online', 'Offline', 'Maintenance'])],
            'last_seen_at' => ['nullable', 'date'],
            'last_restart_at' => ['nullable', 'date'],
        ]);

        // Device ID yang dikosongkan saat edit tidak boleh menghapus
        // identitas perangkat yang sudah ada.
        if (array_key_exists('device_id', $data) && blank($data['device_id'])) {
            unset($data['device_id']);
        }

        // Warna tema selalu huruf kecil (#1F6FEB -> #1f6feb) agar firmware
        // cukup mem-parse satu format.
        if (isset($data['theme_color'])) {
            $data['theme_color'] = Str::lower($data['theme_color']);
        }

        $device->update($data);
        Audit::log($request, 'device', 'device.updated', 'Perangkat diubah: '.$device->name, ['device_id' => $device->id]);

        return response()->json($device->fresh());
    }

    /**
     * Heartbeat dari perangkat fisik (ESP32) lewat bindings {device}.
     *
     * Autentikasi sudah dijamin middleware device.key; parameter {device}
     * dipakai sebagai pengaman tambahan agar key perangkat A tidak bisa
     * melapor atas nama perangkat B.
     */
    public function heartbeat(Request $request, Device $device)
    {
        $authenticated = $request->attributes->get(EnsureDeviceKey::REQUEST_ATTRIBUTE);
        abort_unless($authenticated instanceof Device && $authenticated->is($device), 403, 'Device key tidak cocok dengan perangkat ini.');

        return $this->recordHeartbeat($request, $device, includeScan: false);
    }

    public function heartbeatByKey(Request $request)
    {
        $device = $request->attributes->get(EnsureDeviceKey::REQUEST_ATTRIBUTE);

        abort_unless($device instanceof Device, 401, 'Device key tidak valid.');

        return $this->recordHeartbeat($request, $device, includeScan: true);
    }

    /**
     * Catat heartbeat perangkat dan susun balasan untuk layar alat.
     *
     * Firmware v1.6.0 hanya membaca field lama (attendanceWindow,
     * restart_requested, scan_requested, firmware, ota). Field baru
     * (`display`, `capabilities`, `boot_acknowledged`) bersifat opsional
     * sehingga firmware lama tetap berjalan tanpa perubahan.
     *
     * @return JsonResponse
     */
    private function recordHeartbeat(Request $request, Device $device, bool $includeScan)
    {
        $data = $request->validate([
            'ip_address' => ['nullable', 'ip'],
            'firmware_version' => ['nullable', 'string', 'max:50'],
            'boot_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'firmware_hash' => ['nullable', 'string', 'max:32', 'regex:/^[a-fA-F0-9]+$/'],
            'capabilities' => ['nullable', 'array', 'max:20'],
            'capabilities.*' => ['string', 'max:32'],
        ]);

        $updates = [
            'status' => $device->status === 'Maintenance' ? 'Maintenance' : 'Online',
            'last_seen_at' => now(),
            'offline_alerted_at' => null,
            'ip_address' => $data['ip_address'] ?? $device->ip_address ?? $request->ip(),
        ];

        if (filled($data['firmware_version'] ?? null)) {
            $updates['firmware_version'] = $data['firmware_version'];
        }

        if (filled($data['firmware_hash'] ?? null)) {
            $updates['firmware_hash'] = Str::lower($data['firmware_hash']);
        }

        // boot_id berubah setiap alat menyala ulang. Kalau berubah SAAT
        // perintah restart masih menunggu, artinya alat baru saja menyala
        // ulang: flag dibersihkan dan alat diberi tahu (boot_acknowledged),
        // sehingga bug lama "restart terus tanpa henti" tidak terulang.
        $bootAcknowledged = false;
        $bootId = $data['boot_id'] ?? null;
        $flags = [];

        if (filled($bootId) && $bootId !== $device->boot_id) {
            // boot_id berbeda = alat benar-benar baru menyala. Restart apa pun
            // yang masih menunggu sudah tidak relevan, jadi jangan dikirim lagi.
            $bootAcknowledged = $device->restart_requested_at !== null;

            $flags = ['boot_id' => $bootId, 'booted_at' => now()];

            if ($bootAcknowledged) {
                $updates['last_restart_at'] = $device->restart_requested_at;
                $updates['restart_requested_at'] = null;
            }
        }

        $wasOffline = $device->status !== 'Online' && $device->status !== 'Maintenance';
        $device->update($updates + $flags);

        if ($wasOffline) {
            Audit::log($request, 'device', 'device.online', 'Perangkat kembali online: '.$device->name, ['device_id' => $device->id]);
        }

        $device = $device->fresh();
        $scanRequested = $includeScan && $this->hasActiveScanRequest($device);

        // Konfigurasi WiFi hanya dikirim selama permintaan masih menunggu.
        // Nama field sengaja khusus (wifi_setup_*) agar tidak tertukar dengan
        // wifi_ssid di dalam objek "device" saat firmware mem-parse JSON.
        $wifi = $device->wifi_requested_at === null ? [] : [
            'wifi_setup_ssid' => $device->wifi_ssid,
            'wifi_setup_password' => $device->wifi_password,
            'wifi_setup_token' => $device->wifi_token,
        ];

        return response()->json([
            'message' => 'Heartbeat diterima.',
            'device' => $device,
            ...DeviceRuntime::attendanceWindow(),
            ...$wifi,
            'restart_requested' => $device->restart_requested_at !== null,
            'boot_acknowledged' => $bootAcknowledged,
            'scan_requested' => $scanRequested,
            'scan_expires_in' => $scanRequested
                ? max(0, $device->scan_requested_at->getTimestamp() + self::SCAN_TTL_SECONDS - now()->getTimestamp())
                : 0,
            'firmware' => DeviceRuntime::firmwareStatus($device),
            'ota' => DeviceOta::pending($device),
            'display' => DeviceRuntime::display($device),
            'capabilities' => self::DEVICE_CAPABILITIES,
        ]);
    }

    /**
     * Mulai sesi baca UID dari dashboard Admin. Perangkat akan masuk
     * mode baca pada heartbeat berikutnya dan kembali normal setelah
     * satu kartu terbaca atau sesi kedaluwarsa.
     */
    public function requestScan(Request $request, Device $device)
    {
        abort_if($device->status === 'Maintenance', 409, 'Perangkat sedang dalam mode Maintenance.');
        abort_if($device->status !== 'Online', 409, 'Perangkat sedang offline. Nyalakan perangkat terlebih dahulu.');

        $device->update([
            'scan_requested_at' => now(),
            'scanned_uid' => null,
            'scanned_at' => null,
        ]);

        Audit::log($request, 'device', 'device.scan_requested', 'Mode baca UID diminta: '.$device->name, ['device_id' => $device->id]);

        // Push instan via MQTT (mode server online); tanpa MQTT alat tetap
        // menangkap perintah ini pada heartbeat HTTP berikutnya.
        MqttDeviceBus::publishCommand($device, 'scan', ['expires_in' => self::SCAN_TTL_SECONDS]);

        return response()->json([
            'success' => true,
            'message' => 'Perintah baca UID dikirim. Tempelkan kartu pada perangkat.',
            'status' => 'waiting',
            'device_id' => $device->id,
            'requested_at' => $device->scan_requested_at->toIso8601String(),
            'expires_at' => $device->scan_requested_at->copy()->addSeconds(self::SCAN_TTL_SECONDS)->toIso8601String(),
        ]);
    }

    /**
     * Status sesi baca UID untuk polling dashboard Admin.
     */
    public function scanStatus(Request $request, Device $device)
    {
        $this->expireScanSessions($device);
        $device->refresh();

        if ($device->scanned_uid !== null) {
            return response()->json([
                'status' => 'completed',
                'uid' => $device->scanned_uid,
                'scanned_at' => $device->scanned_at?->toIso8601String(),
            ]);
        }

        if ($device->scan_requested_at !== null) {
            return response()->json([
                'status' => 'waiting',
                'requested_at' => $device->scan_requested_at->toIso8601String(),
                'expires_at' => $device->scan_requested_at->copy()->addSeconds(self::SCAN_TTL_SECONDS)->toIso8601String(),
            ]);
        }

        return response()->json(['status' => 'idle']);
    }

    /**
     * Batalkan sesi baca UID dan bersihkan hasil yang belum dipakai.
     */
    public function cancelScan(Request $request, Device $device)
    {
        $device->update([
            'scan_requested_at' => null,
            'scanned_uid' => null,
            'scanned_at' => null,
        ]);

        Audit::log($request, 'device', 'device.scan_cancelled', 'Mode baca UID dibatalkan: '.$device->name, ['device_id' => $device->id]);

        MqttDeviceBus::publishCommand($device, 'cancel');

        return response()->json([
            'success' => true,
            'message' => 'Sesi baca UID dibatalkan.',
            'status' => 'idle',
        ]);
    }

    /**
     * Hasil baca UID dari perangkat fisik (X-Device-Key).
     */
    public function scanResult(Request $request)
    {
        $device = $this->resolveDeviceFromKey($request);

        $data = $request->validate([
            'uid' => ['required', 'string', 'max:64'],
        ]);

        abort_if($device->scan_requested_at === null, 409, 'Tidak ada permintaan baca UID untuk perangkat ini.');

        if (! $this->hasActiveScanRequest($device)) {
            $device->update(['scan_requested_at' => null]);

            return response()->json(['message' => 'Permintaan baca UID sudah kedaluwarsa.'], 409);
        }

        $uid = RfidUid::normalize($data['uid']);
        abort_if($uid === '', 422, 'UID kartu tidak valid.');

        $device->update([
            'scan_requested_at' => null,
            'scanned_uid' => $uid,
            'scanned_at' => now(),
        ]);

        Audit::log($request, 'device', 'device.scan_result', 'UID kartu dibaca: '.$uid, ['device_id' => $device->id, 'uid' => $uid]);

        return response()->json([
            'success' => true,
            'message' => 'UID kartu diterima.',
            'uid' => $uid,
            'scanned_at' => $device->scanned_at->toIso8601String(),
        ]);
    }

    private function hasActiveScanRequest(Device $device): bool
    {
        return $device->scan_requested_at !== null
            && $device->scan_requested_at->getTimestamp() >= now()->getTimestamp() - self::SCAN_TTL_SECONDS;
    }

    private function expireScanSessions(Device $device): void
    {
        if ($device->scan_requested_at !== null && ! $this->hasActiveScanRequest($device)) {
            $device->update(['scan_requested_at' => null]);
        }

        if ($device->scanned_uid !== null
            && $device->scanned_at !== null
            && $device->scanned_at->getTimestamp() < now()->getTimestamp() - self::SCAN_RESULT_TTL_SECONDS) {
            $device->update(['scanned_uid' => null, 'scanned_at' => null]);
        }
    }

    public function acknowledgeRestart(Request $request)
    {
        $device = $this->resolveDeviceFromKey($request);
        $device->update(['restart_requested_at' => null]);

        return response()->json([
            'message' => 'Perintah restart dikonfirmasi.',
            'device' => $device->fresh(),
        ]);
    }

    /**
     * Simpan SSID/password WiFi baru untuk perangkat (Admin). Alat mengambil
     * nilainya pada heartbeat berikutnya, menyimpannya ke NVS, lalu restart.
     */
    public function setWifi(Request $request, Device $device)
    {
        $data = $request->validate([
            'ssid' => ['required', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:63'],
        ]);

        $ssid = trim($data['ssid']);

        if ($ssid === '') {
            abort(422, 'Nama WiFi (SSID) wajib diisi.');
        }

        $device->update([
            'wifi_ssid' => $ssid,
            'wifi_password' => (string) ($data['password'] ?? ''),
            'wifi_token' => Str::random(16),
            'wifi_requested_at' => now(),
        ]);

        Audit::log(
            $request,
            'device',
            'device.wifi_requested',
            'Ganti WiFi diminta: '.$device->name.' -> '.$ssid,
            ['device_id' => $device->id],
        );

        return response()->json([
            'success' => true,
            'message' => 'Perintah ganti WiFi dicatat. Perangkat akan restart setelah menerima konfigurasi.',
            'wifi_ssid' => $ssid,
            'requested_at' => $device->wifi_requested_at?->toIso8601String(),
        ]);
    }

    /**
     * Konfirmasi dari perangkat bahwa konfigurasi WiFi sudah disimpan ke NVS.
     * Password dan token langsung dihapus; SSID terakhir tetap disimpan agar
     * dashboard masih bisa menampilkan jaringan yang dipakai.
     */
    public function acknowledgeWifi(Request $request)
    {
        $device = $this->resolveDeviceFromKey($request);

        $data = $request->validate([
            'token' => ['required', 'string', 'max:32'],
        ]);

        if ($device->wifi_requested_at === null) {
            return response()->json([
                'success' => true,
                'message' => 'Tidak ada permintaan ganti WiFi yang menunggu.',
            ]);
        }

        if (! hash_equals((string) $device->wifi_token, (string) $data['token'])) {
            return response()->json([
                'success' => false,
                'message' => 'Token ganti WiFi sudah tidak berlaku.',
            ], 409);
        }

        $ssid = $device->wifi_ssid;

        $device->update([
            'wifi_password' => null,
            'wifi_token' => null,
            'wifi_requested_at' => null,
        ]);

        Audit::log(
            $request,
            'device',
            'device.wifi_applied',
            'WiFi baru diterapkan: '.$device->name.' -> '.$ssid,
            ['device_id' => $device->id],
        );

        return response()->json([
            'success' => true,
            'message' => 'Konfigurasi WiFi dikonfirmasi perangkat.',
            'wifi_ssid' => $ssid,
        ]);
    }

    /**
     * Buat device key baru untuk perangkat (Admin). Key lama langsung
     * tidak berlaku; key baru ditampilkan sekali saja di respons.
     */
    public function rotateKey(Request $request, Device $device)
    {
        $deviceKey = 'ptd_'.Str::random(32);
        $device->device_key = hash('sha256', $deviceKey);
        $device->save();

        Audit::log($request, 'device', 'device.key_rotated', 'Device key diperbarui: '.$device->name, ['device_id' => $device->id]);

        return response()->json([
            'success' => true,
            'message' => 'Device key baru berhasil dibuat. Simpan key ini karena tidak akan ditampilkan lagi.',
            'device_key' => $deviceKey,
            'device_key_plain' => $deviceKey,
        ]);
    }

    public function restart(Request $request, Device $device)
    {
        $device->update([
            'last_restart_at' => now(),
            'restart_requested_at' => now(),
        ]);

        Audit::log($request, 'device', 'device.restart', 'Perintah restart: '.$device->name, ['device_id' => $device->id]);

        MqttDeviceBus::publishCommand($device, 'restart');

        return response()->json([
            'message' => 'Perintah restart dicatat. Perangkat akan restart pada heartbeat berikutnya.',
            'device' => $device->fresh(),
        ]);
    }

    private function resolveDeviceFromKey(Request $request): Device
    {
        $device = $request->attributes->get(EnsureDeviceKey::REQUEST_ATTRIBUTE);

        abort_unless($device instanceof Device, 401, 'Device key tidak valid.');

        return $device;
    }

    public function destroy(Request $request, Device $device)
    {
        Audit::log($request, 'device', 'device.deleted', 'Perangkat dihapus: '.$device->name, ['device_id' => $device->id]);
        $device->delete();

        return response()->json(['message' => 'Perangkat berhasil dihapus.']);
    }
}
