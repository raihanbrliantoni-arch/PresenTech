<?php

namespace Tests\Feature;

use App\Console\Commands\DeviceDiscoveryCommand;
use ReflectionMethod;
use Tests\TestCase;

class DeviceDiscoveryTest extends TestCase
{
    private function parseSocketName(?string $name): ?string
    {
        $method = new ReflectionMethod(DeviceDiscoveryCommand::class, 'ipFromSocketName');
        $method->setAccessible(true);

        return $method->invoke(new DeviceDiscoveryCommand, $name);
    }

    /**
     * @param  array<string, array<string, mixed>>  $interfaces
     * @return list<string>
     */
    private function lanIpsFromInterfaces(array $interfaces): array
    {
        $method = new ReflectionMethod(DeviceDiscoveryCommand::class, 'lanIpsFromInterfaces');
        $method->setAccessible(true);

        return $method->invoke(new DeviceDiscoveryCommand, $interfaces);
    }

    public function test_ip_lokal_dibaca_dari_nama_socket_udp(): void
    {
        $this->assertSame('192.168.1.5', $this->parseSocketName('192.168.1.5:52344'));
        $this->assertSame('10.0.0.8', $this->parseSocketName('10.0.0.8:8888'));
    }

    public function test_nama_socket_tidak_valid_diabaikan(): void
    {
        $this->assertNull($this->parseSocketName(null));
        $this->assertNull($this->parseSocketName(''));
        $this->assertNull($this->parseSocketName('bukan-ip:80'));
        $this->assertNull($this->parseSocketName('[::1]:8888'));
    }

    public function test_ip_dari_adapter_yang_mati_tidak_ditawarkan_ke_alat(): void
    {
        $interfaces = [
            'Wi-Fi' => [
                'up' => false,
                'unicast' => [
                    ['address' => '192.168.1.5', 'family' => 2],
                ],
            ],
            'Ethernet' => [
                'up' => true,
                'unicast' => [
                    ['address' => '192.168.1.6', 'family' => 2],
                ],
            ],
        ];

        $this->assertSame(['192.168.1.6'], $this->lanIpsFromInterfaces($interfaces));
    }

    public function test_loopback_link_local_dan_ipv6_diabaikan(): void
    {
        $interfaces = [
            'Loopback' => [
                'up' => true,
                'unicast' => [
                    ['address' => '127.0.0.1', 'family' => 2],
                    ['address' => '::1', 'family' => 10],
                ],
            ],
            'Ethernet' => [
                'up' => true,
                'unicast' => [
                    ['address' => '169.254.10.10', 'family' => 2],
                    ['address' => 'fe80::1', 'family' => 10],
                    ['address' => '10.10.10.2', 'family' => 2],
                ],
            ],
            // Platform tanpa flag 'up' dianggap hidup.
            'Tambahan' => [
                'unicast' => [
                    ['address' => '172.16.0.9', 'family' => 2],
                ],
            ],
        ];

        $this->assertSame(['10.10.10.2', '172.16.0.9'], $this->lanIpsFromInterfaces($interfaces));
    }
}
