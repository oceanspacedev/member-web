<?php

namespace App\Filament\Pages\Auth;

use Apriansyahrs\MekayaTheme\Auth\MekayaLogin;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Login panel admin dengan email + password. Login WhatsApp OTP ada di halaman terpisah
 * (WhatsAppLogin), ditautkan lewat tombol di bawah form — pola yang sama dengan dnd-web.
 * Hanya user ber-peran "admin" yang bisa masuk (User::canAccessPanel).
 */
class Login extends MekayaLogin
{
    public function getHeading(): string|Htmlable|null
    {
        return 'Masuk panel admin';
    }
}
