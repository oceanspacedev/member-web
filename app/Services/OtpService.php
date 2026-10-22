<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Services\WhatsApp\WhatsAppDeliveryException;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function __construct(private readonly WhatsAppSender $whatsApp) {}

    /**
     * Buat kode OTP baru dan kirim via WhatsApp. Mengembalikan detik sampai kode kedaluwarsa.
     */
    public function send(string $phone): int
    {
        $config = config('loyalty.otp');

        $latest = OtpCode::where('phone', $phone)->latest('id')->first();
        if ($latest && $latest->created_at->diffInSeconds(now()) < $config['resend_cooldown']) {
            $wait = $config['resend_cooldown'] - (int) $latest->created_at->diffInSeconds(now());

            throw ValidationException::withMessages([
                'phone' => "Tunggu {$wait} detik sebelum meminta kode baru.",
            ]);
        }

        $code = str_pad((string) random_int(0, 10 ** $config['length'] - 1), $config['length'], '0', STR_PAD_LEFT);

        OtpCode::where('phone', $phone)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        $otp = OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addSeconds($config['ttl']),
        ]);

        try {
            $this->whatsApp->send($phone, $this->message($code, $config['ttl']), 'otp');
        } catch (WhatsAppDeliveryException $e) {
            // Kode tidak pernah sampai: hapus agar member bisa langsung minta ulang tanpa menunggu cooldown.
            $otp->delete();
            report($e);

            throw ValidationException::withMessages([
                'phone' => 'Kode OTP gagal dikirim ke WhatsApp. Pastikan nomor aktif di WhatsApp lalu coba lagi.',
            ]);
        }

        return $config['ttl'];
    }

    private function message(string $code, int $ttl): string
    {
        $minutes = intdiv($ttl, 60);

        return "Kode verifikasi *Space Club* kamu:\n\n*{$code}*\n\n"
            ."Berlaku {$minutes} menit. Jangan bagikan kode ini kepada siapa pun, termasuk yang mengaku dari Space Club.";
    }

    /**
     * Validasi kode. Melempar ValidationException bila salah / kedaluwarsa.
     */
    public function verify(string $phone, string $code): void
    {
        $otp = OtpCode::where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp || $otp->attempts >= config('loyalty.otp.max_attempts')) {
            throw ValidationException::withMessages(['code' => 'Kode sudah kedaluwarsa. Minta kode baru.']);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            throw ValidationException::withMessages(['code' => 'Kode OTP salah.']);
        }

        $otp->update(['consumed_at' => now()]);
    }
}
