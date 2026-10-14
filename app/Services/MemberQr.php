<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Payload QR member: LC1.<member_code>.<expires_unix>.<signature>
 * Ditandatangani HMAC dengan APP_KEY dan berlaku singkat agar screenshot lama tidak bisa dipakai.
 */
class MemberQr
{
    private const PREFIX = 'LC1';

    /**
     * @return array{payload: string, expires_at: int}
     */
    public function issue(User $user): array
    {
        $expires = now()->addSeconds(config('loyalty.qr_ttl'))->getTimestamp();

        return [
            'payload' => implode('.', [self::PREFIX, $user->member_code, $expires, $this->sign($user->member_code, $expires)]),
            'expires_at' => $expires,
        ];
    }

    public function resolve(string $payload): User
    {
        $parts = explode('.', trim($payload));

        if (count($parts) !== 4 || $parts[0] !== self::PREFIX || ! ctype_digit($parts[2])) {
            throw ValidationException::withMessages(['qr' => 'QR bukan QR member Ocean Space.']);
        }

        [, $code, $expires, $signature] = $parts;

        if (! hash_equals($this->sign($code, (int) $expires), $signature)) {
            throw ValidationException::withMessages(['qr' => 'QR tidak valid.']);
        }

        if ((int) $expires < now()->getTimestamp()) {
            throw ValidationException::withMessages(['qr' => 'QR sudah kedaluwarsa. Minta member membuka ulang QR-nya.']);
        }

        $user = User::where('member_code', $code)->first();

        if (! $user) {
            throw ValidationException::withMessages(['qr' => 'Member tidak ditemukan.']);
        }

        return $user;
    }

    private function sign(string $code, int $expires): string
    {
        return substr(hash_hmac('sha256', "{$code}|{$expires}", (string) config('app.key')), 0, 32);
    }
}
