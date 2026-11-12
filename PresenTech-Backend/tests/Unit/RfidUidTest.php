<?php

namespace Tests\Unit;

use App\Support\RfidUid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RfidUidTest extends TestCase
{
    #[DataProvider('uidCases')]
    public function test_menormalkan_uid_menjadi_format_hex_konsisten(?string $input, string $expected): void
    {
        $this->assertSame($expected, RfidUid::normalize($input));
    }

    public static function uidCases(): array
    {
        return [
            'huruf kecil' => ['a1b2c3d4', 'A1B2C3D4'],
            'pemisah titik dua' => ['a1:b2:c3:d4', 'A1B2C3D4'],
            'pemisah spasi dan strip' => ['a1 b2-c3 d4', 'A1B2C3D4'],
            'awalan 0x' => ['0xA1B2', 'A1B2'],
            'byte dengan nol di depan' => ['39d7641', '039D7641'],
            'uid non-hex tetap dipertahankan' => ['uid123', 'UID123'],
            'string kosong' => ['', ''],
            'null' => [null, ''],
        ];
    }
}
