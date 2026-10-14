<?php

namespace App\Services\WhatsApp;

use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gateway WAGHub (https://waghub.mekayastudio.com) — API v1 /messages.
 */
class WagHubWhatsAppSender implements WhatsAppSender
{
    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly int $timeout = 10,
    ) {}

    public function send(string $phone, string $message, string $purpose = 'notification'): void
    {
        if ($this->url === '' || $this->token === '') {
            throw new WhatsAppDeliveryException('WAG_URL / WAG_TOKEN belum diisi di .env.');
        }

        $key = 'ocean-space-'.Str::uuid();
        // OTP basi tidak boleh terkirim belakangan; notifikasi lain boleh menunggu sehari.
        $expiresAt = $purpose === 'otp' ? now()->addSeconds(config('loyalty.otp.ttl')) : now()->addDay();

        try {
            $response = Http::connectTimeout(3)
                ->timeout($this->timeout)
                // TLS tetap diverifikasi; CaBundle dipakai karena PHP di Windows sering tanpa bundel CA.
                ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
                ->withoutRedirecting()
                ->acceptJson()
                ->withToken($this->token)
                ->withHeaders(['Idempotency-Key' => $key])
                ->post(rtrim($this->url, '/').'/api/v1/messages', [
                    'idempotency_key' => $key,
                    'recipient' => ['type' => 'phone', 'value' => $phone],
                    'message' => ['type' => 'text', 'text' => $message],
                    'purpose' => $purpose,
                    'mode' => 'sync',
                    'client_reference' => 'ocean-space',
                    'expires_at' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
                ]);
        } catch (ConnectionException $e) {
            Log::error("[WhatsApp:waghub] tidak bisa terhubung: {$e->getMessage()}");

            throw new WhatsAppDeliveryException('Gateway WhatsApp tidak bisa dihubungi.', previous: $e);
        }

        if ($response->failed()) {
            $reason = $response->json('message') ?? $response->json('error.message') ?? 'HTTP '.$response->status();
            Log::warning("[WhatsApp:waghub] gagal kirim ke {$phone}: HTTP {$response->status()} {$response->body()}");

            throw new WhatsAppDeliveryException("Gateway WhatsApp menolak pesan: {$reason}", $response->status());
        }

        Log::info("[WhatsApp:waghub] ({$purpose}) terkirim ke {$phone}", ['message_id' => $response->json('data.id')]);
    }
}
