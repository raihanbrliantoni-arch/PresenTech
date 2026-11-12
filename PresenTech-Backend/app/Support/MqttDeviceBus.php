<?php

namespace App\Support;

use App\Models\Device;
use App\Models\Setting;
use Illuminate\Support\Str;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Throwable;

/**
 * Bus pesan MQTT untuk perangkat ESP32 (mode server online).
 *
 * Sumber konfigurasi (urut prioritas):
 *   1. Pengaturan di web (menu Pengaturan -> Koneksi MQTT) bila MQTT diaktifkan.
 *   2. Variabel .env (MQTT_HOST, MQTT_PORT, dst) sebagai cadangan.
 *
 * Topik (prefix dari pengaturan / config presentech.mqtt.topic_prefix):
 *   <prefix>/d/<hash8>/heartbeat    alat -> bridge
 *   <prefix>/d/<hash8>/attendance   alat -> bridge
 *   <prefix>/d/<hash8>/scan-result  alat -> bridge
 *   <prefix>/d/<hash8>/cmd/#        web/bridge -> alat
 *   <prefix>/d/<hash8>/result       bridge -> alat (hasil absensi/scan)
 *
 * hash8 = 8 karakter pertama sha256(device_key).
 */
final class MqttDeviceBus
{
    /**
     * @return array{host: string, port: int, tls: bool, username: string, password: string, prefix: string}
     */
    public static function connection(): array
    {
        $settings = Setting::current();
        $useSettings = (bool) $settings->mqtt_enabled && trim((string) $settings->mqtt_broker) !== '';

        if ($useSettings) {
            [$host, $urlPort] = self::parseBroker((string) $settings->mqtt_broker);
            $port = (int) ($settings->mqtt_port ?: $urlPort ?: 1883);

            return [
                'host' => $host,
                'port' => $port,
                'tls' => (bool) $settings->mqtt_tls,
                'username' => (string) ($settings->mqtt_username ?? ''),
                'password' => (string) ($settings->mqtt_password ?? ''),
                'prefix' => self::normalizePrefix((string) ($settings->mqtt_topic ?: 'presentech')),
            ];
        }

        return [
            'host' => (string) config('presentech.mqtt.host', ''),
            'port' => (int) config('presentech.mqtt.port', 8883),
            'tls' => (bool) config('presentech.mqtt.tls', true),
            'username' => (string) config('presentech.mqtt.username', ''),
            'password' => (string) config('presentech.mqtt.password', ''),
            'prefix' => self::normalizePrefix((string) config('presentech.mqtt.topic_prefix', 'presentech')),
        ];
    }

    public static function enabled(): bool
    {
        return self::connection()['host'] !== '';
    }

    /**
     * "mqtt://broker.sekolah:1883/path" -> ["broker.sekolah", 1883].
     *
     * @return array{0: string, 1: int|null}
     */
    private static function parseBroker(string $broker): array
    {
        $broker = trim($broker);
        $broker = (string) preg_replace('#^[a-z]+://#i', '', $broker);
        $broker = explode('/', $broker)[0];
        $port = null;

        if (str_contains($broker, ':')) {
            [$broker, $portPart] = explode(':', $broker, 2);
            $port = ctype_digit($portPart) ? (int) $portPart : null;
        }

        return [$broker, $port];
    }

    private static function normalizePrefix(string $topic): string
    {
        $topic = trim($topic, " \t\n\r\0\x0B/");

        if ($topic === '') {
            return 'presentech';
        }

        // Field web bernama "Topic"; yang dipakai alat adalah prefix
        // (mis. "presentech/attendance" -> "presentech").
        return explode('/', $topic)[0];
    }

    public static function topicRoot(Device $device): string
    {
        return self::connection()['prefix'].'/d/'.substr((string) $device->device_key, 0, 8);
    }

    /**
     * Publish sekali-jalan (connect -> publish -> disconnect).
     */
    public static function publish(Device $device, string $topic, array $payload): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $connection = self::connection();

        try {
            $client = new MqttClient(
                $connection['host'],
                $connection['port'],
                'presen-web-'.substr((string) Str::orderedUuid(), 0, 8),
            );

            $client->connect(self::connectionSettings(), true);
            $client->publish(self::topicRoot($device).'/'.$topic, json_encode($payload), 0, false);
            $client->disconnect();

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public static function publishCommand(Device $device, string $command, array $payload = []): bool
    {
        return self::publish($device, "cmd/{$command}", $payload);
    }

    public static function publishResult(Device $device, array $payload): bool
    {
        return self::publish($device, 'result', $payload);
    }

    public static function makeClient(): MqttClient
    {
        $connection = self::connection();

        return new MqttClient(
            $connection['host'],
            $connection['port'],
            'presen-bridge-'.substr((string) Str::orderedUuid(), 0, 8),
        );
    }

    public static function connectionSettings(): ConnectionSettings
    {
        $connection = self::connection();

        $settings = ConnectionSettings::new()
            ->setConnectTimeout(5)
            ->setKeepAliveInterval(15)
            ->setUseTls($connection['tls']);

        if ((bool) config('presentech.mqtt.allow_self_signed', false)) {
            $settings->setTlsSelfSignedAllowed(true);
            $settings->setTlsVerifyPeer(false);
        }

        if ($connection['username'] !== '') {
            $settings->setUsername($connection['username']);
            $settings->setPassword($connection['password']);
        }

        return $settings;
    }
}
