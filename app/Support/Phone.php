<?php

namespace App\Support;

class Phone
{
    /**
     * Normalisasi nomor Indonesia ke format 62xxxxxxxxxx.
     * Mengembalikan null bila bukan nomor HP yang valid.
     */
    public static function normalize(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input);

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return preg_match('/^628\d{7,12}$/', $digits) ? $digits : null;
    }
}
