<?php

namespace App\Support;

final class RfidUid
{
    public static function normalize(?string $uid): string
    {
        $value = trim((string) $uid);

        if ($value === '') {
            return '';
        }

        $compact = preg_replace('/[\s:\-\._]/', '', preg_replace('/0x/i', '', $value));

        if ($compact !== '' && ctype_xdigit($compact)) {
            $normalized = strtoupper($compact);

            return strlen($normalized) % 2 === 0 ? $normalized : '0'.$normalized;
        }

        return strtoupper($value);
    }
}
