<?php

namespace App\Support;

class Media
{
    /**
     * URL publik untuk path di disk "media". Memakai host dari request agar
     * gambar tetap terbuka saat API diakses lewat IP LAN dari HP.
     */
    public static function url(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (preg_match('#^https?://#', $path)) {
            return $path;
        }

        return asset('media/'.ltrim($path, '/'));
    }
}
