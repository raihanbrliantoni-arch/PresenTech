<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function stream_socket_recvfrom;
use function stream_socket_sendto;
use function stream_socket_server;

/**
 * Listener UDP discovery untuk perangkat ESP32.
 *
 * Perangkat yang belum tahu alamat server mengirim broadcast
 * "PRESENTECH_DISCOVERY" ke UDP 8888; command ini menjawab dengan URL API
 * dari IP LAN aktual mesin ini sehingga alat tidak perlu dikonfigurasi IP.
 *
 * Jalankan bersama server: php artisan devices:discovery
 */
#[Signature('devices:discovery {--port=8888} {--http-port=8000} {--ip=}')]
#[Description('Listener UDP discovery: alat mencari server otomatis tanpa IP')]
class DeviceDiscoveryCommand extends Command
{
    public function handle(): int
    {
        $port = max(1024, (int) $this->option('port'));
        $httpPort = max(1, (int) $this->option('http-port'));
        $forcedIp = $this->option('ip');
        $forcedIp = is_string($forcedIp) && filter_var($forcedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? $forcedIp
            : null;

        $socket = @stream_socket_server("udp://0.0.0.0:{$port}", $errno, $errstr, STREAM_SERVER_BIND);

        if ($socket === false) {
            $this->error("Gagal membuka UDP {$port}: {$errstr} ({$errno}).");

            return self::FAILURE;
        }

        $this->info("Listener discovery aktif di UDP {$port}, API dilaporkan di port {$httpPort}.");

        while (true) {
            $packet = @stream_socket_recvfrom($socket, 512, 0, $peer);

            if ($packet === false || $packet === '') {
                continue;
            }

            if (! str_starts_with($packet, 'PRESENTECH_DISCOVERY')) {
                continue;
            }

            $peerName = is_string($peer) ? $peer : null;
            $candidates = $forcedIp !== null
                ? [$forcedIp]
                : $this->localIpCandidates($peerName);

            if ($candidates === []) {
                continue;
            }

            // Kirim satu balasan per IP LAN. Komputer dengan Wi-Fi + Ethernet
            // punya dua alamat; alat memilih yang benar-benar bisa dijangkau.
            foreach ($candidates as $ip) {
                $reply = json_encode([
                    'api_base' => "http://{$ip}:{$httpPort}/api",
                    'ip' => $ip,
                    'service' => 'PresenTech',
                ], JSON_UNESCAPED_SLASHES);

                @stream_socket_sendto($socket, (string) $reply, 0, $peer);

                $this->output->writeln(now()->format('H:i:s')." Balas discovery ke {$peerName}: {$reply}");
            }
        }
    }

    /**
     * Daftar IP LAN yang mungkin bisa dijangkau alat: IP pada jalur peer
     * lebih dulu, lalu IP LAN lain sebagai cadangan.
     *
     * @return list<string>
     */
    private function localIpCandidates(?string $peer): array
    {
        $candidates = [];
        $peerIp = $this->ipFromSocketName($peer);

        if ($peerIp !== null) {
            $preferred = $this->localIpForPeer($peerIp);

            if ($preferred !== null) {
                $candidates[] = $preferred;
            }
        }

        foreach ($this->lanIps() as $ip) {
            if (! in_array($ip, $candidates, true)) {
                $candidates[] = $ip;
            }
        }

        return $candidates;
    }

    /**
     * IP lokal yang dipakai OS untuk menjangkau perangkat. Ini penting saat
     * komputer punya lebih dari satu IP di subnet yang sama (mis. Wi-Fi dan
     * Ethernet): alat hanya bisa menghubungi IP pada jalur yang sama.
     */
    private function localIpForPeer(?string $peer): ?string
    {
        $peerIp = $this->ipFromSocketName($peer);

        if ($peerIp !== null) {
            // UDP "connect" tidak mengirim paket; hanya membuat OS memilih
            // alamat sumber yang benar untuk tujuan tersebut.
            $probe = @stream_socket_client("udp://{$peerIp}:9", $errno, $errstr, 0.2, STREAM_CLIENT_CONNECT);

            if ($probe !== false) {
                $localIp = $this->ipFromSocketName((string) stream_socket_get_name($probe, false));
                fclose($probe);

                if ($localIp !== null && ! str_starts_with($localIp, '127.') && in_array($localIp, $this->lanIps(), true)) {
                    return $localIp;
                }
            }
        }

        return $this->lanIps()[0] ?? null;
    }

    private function ipFromSocketName(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        $colon = strrpos($name, ':');
        $ip = $colon === false ? $name : substr($name, 0, $colon);
        $ip = trim($ip, '[]');

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : null;
    }

    /**
     * Semua IP LAN aktual mesin ini (IPv4, bukan loopback/link-local) — yang
     * terlihat oleh perangkat lain di jaringan yang sama.
     *
     * @return list<string>
     */
    private function lanIps(): array
    {
        if (! function_exists('net_get_interfaces')) {
            return [];
        }

        return $this->lanIpsFromInterfaces(net_get_interfaces());
    }

    /**
     * Ambil IPv4 LAN dari daftar interface OS. Adapter yang sedang mati
     * (mis. Wi-Fi terputus) dilewati walau IP-nya masih terpasang: alamat itu
     * tidak bisa dijangkau alat, dan menawarkannya membuat alat memilih
     * alamat yang salah lalu berhenti menyapa server.
     *
     * @param  array<string, array<string, mixed>>  $interfaces
     * @return list<string>
     */
    private function lanIpsFromInterfaces(array $interfaces): array
    {
        $ips = [];

        foreach ($interfaces as $interface) {
            if (($interface['up'] ?? true) === false) {
                continue;
            }

            foreach ($interface['unicast'] ?? [] as $address) {
                $ip = (string) ($address['address'] ?? '');

                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    continue;
                }

                if (str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.')) {
                    continue;
                }

                $ips[] = $ip;
            }
        }

        return $ips;
    }
}
