<?php

namespace App\Services;

use App\Models\Voucher;
use Illuminate\Validation\ValidationException;

/**
 * Payload QR voucher: LCV1.<code>.<expires_unix>.<signature>
 * Sama seperti QR member: ditandatangani dan berlaku singkat, jadi screenshot lama tidak bisa dipakai.
 */
class VoucherQr
{
    public const PREFIX = 'LCV1';

    /**
     * @return array{payload: string, expires_at: int}
     */
    public function issue(Voucher $voucher): array
    {
        $expires = now()->addSeconds(config('loyalty.qr_ttl'))->getTimestamp();

        return [
            'payload' => implode('.', [self::PREFIX, $voucher->code, $expires, $this->sign($voucher->code, $expires)]),
            'expires_at' => $expires,
        ];
    }

    public function resolve(string $payload): Voucher
    {
        $parts = explode('.', trim($payload));

        if (count($parts) !== 4 || $parts[0] !== self::PREFIX || ! ctype_digit($parts[2])) {
            throw ValidationException::withMessages(['qr' => 'QR bukan QR voucher Space Club.']);
        }

        [, $code, $expires, $signature] = $parts;

        if (! hash_equals($this->sign($code, (int) $expires), $signature)) {
            throw ValidationException::withMessages(['qr' => 'QR voucher tidak valid.']);
        }

        if ((int) $expires < now()->getTimestamp()) {
            throw ValidationException::withMessages(['qr' => 'QR voucher sudah kedaluwarsa. Minta member membuka ulang vouchernya.']);
        }

        return Voucher::with(['user', 'brand', 'outlet'])->where('code', $code)->first()
            ?? throw ValidationException::withMessages(['qr' => 'Voucher tidak ditemukan.']);
    }

    private function sign(string $code, int $expires): string
    {
        // Konteks "voucher" membedakan tanda tangan ini dari QR member.
        return substr(hash_hmac('sha256', "voucher|{$code}|{$expires}", (string) config('app.key')), 0, 32);
    }
}
