<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Aturan teks untuk layar perangkat (LCD 16/20 kolom).
 *
 * Nama panjang dipendekkan di server agar alat tidak perlu tahu cara
 * memotong kata, dan hasilnya konsisten di semua jalur (HTTP maupun MQTT).
 * Singkatan yang lazim dipakai di sekolah (RPL, TKJ, XII) tetap utuh sehingga
 * "Rekayasa Perangkat Lunak 1" menjadi "RPL 1", bukan "Rekayasa Perangkat…".
 */
final class DeviceDisplay
{
    /**
     * Singkatan yang tidak boleh digabung/dipotong karena maknanya berubah.
     *
     * @var list<string>
     */
    private const ACRONYMS = ['RPL', 'TKJ', 'TKRO', 'TBSM', 'TK', 'AKL', 'MM', 'MTK', 'IPA', 'IPS', 'BHS', 'PJOK', 'BP', 'BK'];

    /**
     * Batas praktis nama di layar alat 20 kolom: "M. R. Putra" (11) muat,
     * "M. R. B. Putra" (13) masih kebaca, "M. Raihan B. Putra" (18) sudah
     * terlalu padat sehingga nama belakang jadi lebih penting.
     */
    private const NAME_LIMIT = 12;

    /**
     * Frasa panjang yang paling sering muncul di nama kelas/jurusan.
     *
     * @var array<string, string>
     */
    private const ABBREVIATIONS = [
        'REKAYASA PERANGKAT LUNAK' => 'RPL',
        'TEKNIK KOMPUTER DAN JARINGAN' => 'TKJ',
        'TEKNIK JARINGAN KOMPUTER DAN TELEKOMUNIKASI' => 'TJKT',
        'TEKNIK KENDARAAN RINGAN OTOMOTIF' => 'TKRO',
        'TEKNIK BISNIS SEPEDA MOTOR' => 'TBSM',
        'AKUNTANSI DAN KEUANGAN LEMBAGA' => 'AKL',
        'MULTIMEDIA' => 'MM',
        'MATEMATIKA' => 'MTK',
        'PENDIDIKAN JASMANI' => 'PJOK',
        'BAHASA INDONESIA' => 'BIN',
        'BAHASA INGGRIS' => 'BING',
        'SEKOLAH MENENGAH KEJURUAN' => 'SMK',
        'SEKOLAH MENENGAH ATAS' => 'SMA',
        'PRESENTECH' => 'PresenTech',
    ];

    /**
     * Pendekkan teks agar muat di layar alat tanpa memotong kata di tengah.
     */
    public static function shorten(?string $text, int $limit = 20, string $fallback = '-'): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');

        if ($value === '') {
            return $fallback;
        }

        $value = self::expand($value);

        if (self::length($value) <= $limit) {
            return $value;
        }

        // Buang kata terakhir satu per satu sampai muat, lalu beri elipsis.
        $words = explode(' ', $value);

        while (count($words) > 1) {
            array_pop($words);
            $candidate = rtrim(implode(' ', $words), ' .,-');

            if (self::length($candidate) + 1 <= $limit) {
                return $candidate.'…';
            }
        }

        // Kata tunggal yang terlalu panjang: potong paksa.
        return self::substr($value, $limit - 1).'…';
    }

    /**
     * Ganti frasa panjang dengan singkatan sekolah yang lazim, dan rapikan
     * spasi ganda. Teks lain dibiarkan apa adanya.
     */
    public static function expand(string $value): string
    {
        $upper = Str::upper($value);

        // Frasa terpanjang didahulukan supaya "TEKNIK KOMPUTER DAN JARINGAN"
        // tidak lebih dulu kena aturan yang lebih pendek.
        $phrases = self::ABBREVIATIONS;
        uksort($phrases, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($phrases as $phrase => $short) {
            if (str_contains($upper, $phrase)) {
                // Batas kata ditulis manual: \b di PCRE tidak mengenal
                // karakter non-ASCII secara konsisten pada string UTF-8.
                $value = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/iu', $short, $value) ?? $value;
                $upper = Str::upper($value);
            }
        }

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * Nama siswa untuk layar: inisial depan + nama belakang, mis.
     * "Muhammad Raihan Briliantoni Putra" → "M. Raihan B. Putra".
     * Nama pendek (≤ 3 kata) tidak diubah.
     */
    public static function shortPersonName(?string $name, int $limit = 16): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');

        if ($value === '') {
            return '-';
        }

        $words = explode(' ', $value);
        $count = count($words);

        // Gelar di belakang nama (S.Pd, S.Kom, dst.) tidak dihitung sebagai nama.
        $isTitle = static fn (string $word): bool => (bool) preg_match('/^[A-Z](\.[A-Z])+\.?$/', $word);
        $titles = [];
        while ($count > 1 && $isTitle($words[$count - 1])) {
            array_unshift($titles, array_pop($words));
            $count--;
        }

        if ($count > 2) {
            $first = $words[0];
            $last = $words[$count - 1];
            array_pop($words);

            // Nama belakang tetap utuh; kata depan diganti inisial.
            $firstInitial = self::substr($first, 1).'.';
            $middleInitials = array_map(static fn (string $word): string => self::substr($word, 1).'.', array_slice($words, 1));
            $tail = trim(implode(' ', $middleInitials).' '.$last.' '.implode(' ', $titles));

            // Pilih bentuk terpanjang yang masih muat: "M. R. B. Putra" ->
            // "M. R. Putra" -> "M. Putra".
            $value = $firstInitial.' '.$tail;
            while ($middleInitials !== [] && self::length($value) > self::NAME_LIMIT) {
                array_pop($middleInitials);
                $tail = trim(implode(' ', $middleInitials).' '.$last.' '.implode(' ', $titles));
                $value = self::length($firstInitial.' '.$tail) <= self::NAME_LIMIT
                    ? $firstInitial.' '.$tail
                    : '';
            }

            if ($value === '') {
                // Nama belakang saja sudah terlalu panjang: potong dengan aman.
                $value = $firstInitial.' '.$last.' '.implode(' ', $titles);
            }

            return $value;
        }

        return self::shorten($value, $limit);
    }

    /**
     * Versi ringkas untuk layar sempit: satu huruf per nama depan/tengah.
     */
    public static function initialsName(?string $name, int $limit = 16): string
    {
        $value = self::shorten($name, 999, '');

        if ($value === '') {
            return '-';
        }

        $words = explode(' ', $value);

        if (count($words) < 3) {
            return self::shorten($value, $limit);
        }

        $last = array_pop($words);
        $initials = implode('. ', array_map(static fn (string $word): string => self::substr($word, 1), $words));

        return self::shorten($initials.'. '.$last, $limit);
    }

    /**
     * Panjang string dalam karakter (multibyte-safe).
     */
    private static function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    private static function substr(string $value, int $length): string
    {
        $cut = mb_substr($value, 0, max(1, $length), 'UTF-8');

        return $cut === '' ? $value : $cut;
    }
}
