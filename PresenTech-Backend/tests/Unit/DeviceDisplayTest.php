<?php

namespace Tests\Unit;

use App\Support\DeviceDisplay;
use PHPUnit\Framework\TestCase;

class DeviceDisplayTest extends TestCase
{
    public function test_teks_pendek_tidak_diubah(): void
    {
        $this->assertSame('Lab RPL 1', DeviceDisplay::shorten('Lab RPL 1', 20));
    }

    public function test_teks_kosong_memakai_fallback(): void
    {
        $this->assertSame('-', DeviceDisplay::shorten(null, 20));
        $this->assertSame('Alat', DeviceDisplay::shorten('   ', 20, 'Alat'));
    }

    public function test_teks_panjang_dipotong_di_batas_kata_dengan_elipsis(): void
    {
        $hasil = DeviceDisplay::shorten('Perangkat Absensi Gerbang Utama Sekolah', 20);

        $this->assertLessThanOrEqual(20, mb_strlen($hasil));
        $this->assertSame('Perangkat Absensi…', $hasil);
    }

    public function test_frasa_jurusan_disingkat_agar_tetap_informatif(): void
    {
        $this->assertSame('RPL 1', DeviceDisplay::shorten('Rekayasa Perangkat Lunak 1', 20));
        $this->assertSame('TKJ 2', DeviceDisplay::shorten('Teknik Komputer dan Jaringan 2', 20));
        $this->assertSame('SMK Nusantara', DeviceDisplay::shorten('Sekolah Menengah Kejuruan Nusantara', 20));
    }

    public function test_kata_tunggal_yang_terlalu_panjang_dipotong_paksa(): void
    {
        $hasil = DeviceDisplay::shorten('Perpustakaansentralsekolah', 10);

        $this->assertSame(10, mb_strlen($hasil));
        $this->assertStringEndsWith('…', $hasil);
    }

    public function test_nama_siswa_panjang_dipendekkan_dengan_inisial(): void
    {
        // Nama belakang dipertahankan; inisial tengah dipotong sampai muat.
        $this->assertSame('M. R. Putra', DeviceDisplay::shortPersonName('Muhammad Raihan Briliantoni Putra', 12));
        $this->assertSame('M. R. Putra', DeviceDisplay::shortPersonName('Muhammad Raihan Briliantoni Putra', 20));
        $this->assertSame('Budi Santoso', DeviceDisplay::shortPersonName('Budi Santoso', 20));
    }

    public function test_singkatan_nama_untuk_layar_sempit(): void
    {
        $this->assertSame('M. R. B. Putra', DeviceDisplay::initialsName('Muhammad Raihan Briliantoni Putra', 20));
        $this->assertSame('Budi Santoso', DeviceDisplay::initialsName('Budi Santoso', 20));
    }
}
