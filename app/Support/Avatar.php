<?php

namespace App\Support;

class Avatar
{
    /**
     * Avatar inisial sebagai data URI SVG — tanpa layanan luar (ui-avatars), tetap tampil saat offline.
     */
    public static function initials(?string $name): string
    {
        $initials = collect(preg_split('/\s+/', trim($name ?? '?')))
            ->filter()
            ->take(2)
            ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('') ?: '?';

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" fill="#EEEDEA"/>'
            .'<text x="50%" y="54%" text-anchor="middle" dominant-baseline="middle" font-family="sans-serif" font-size="24" font-weight="700" fill="#18181B">'
            .htmlspecialchars($initials, ENT_XML1).'</text></svg>';

        return 'data:image/svg+xml;utf8,'.rawurlencode($svg);
    }
}
