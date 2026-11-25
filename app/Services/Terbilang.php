<?php

namespace App\Services;

/**
 * Converts a whole rupiah amount to Indonesian words, e.g. 1500 => "seribu lima ratus".
 */
class Terbilang
{
    private const UNITS = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

    public static function rupiah(float $amount): string
    {
        $whole = (int) round(abs($amount));

        return ucfirst(trim(self::words($whole))).' rupiah';
    }

    public static function words(int $number): string
    {
        if ($number === 0) {
            return 'nol';
        }

        return trim(preg_replace('/\s+/', ' ', self::convert($number)));
    }

    private static function convert(int $n): string
    {
        return match (true) {
            $n === 0 => '',
            $n < 12 => self::UNITS[$n],
            $n < 20 => self::convert($n - 10).' belas',
            $n < 100 => self::convert(intdiv($n, 10)).' puluh '.self::convert($n % 10),
            $n < 200 => 'seratus '.self::convert($n - 100),
            $n < 1000 => self::convert(intdiv($n, 100)).' ratus '.self::convert($n % 100),
            $n < 2000 => 'seribu '.self::convert($n - 1000),
            $n < 1_000_000 => self::convert(intdiv($n, 1000)).' ribu '.self::convert($n % 1000),
            $n < 1_000_000_000 => self::convert(intdiv($n, 1_000_000)).' juta '.self::convert($n % 1_000_000),
            $n < 1_000_000_000_000 => self::convert(intdiv($n, 1_000_000_000)).' miliar '.self::convert($n % 1_000_000_000),
            default => self::convert(intdiv($n, 1_000_000_000_000)).' triliun '.self::convert($n % 1_000_000_000_000),
        };
    }
}
