<?php

namespace App\Services\Payment;

use App\Models\User;
use App\Models\VoucherOrder;
use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Xendit Payment Session (POST /sessions, mode PAYMENT_LINK): member membayar di halaman Xendit
 * (QRIS, virtual account, e-wallet — sesuai channel yang aktif di dashboard).
 * Secret key Test Mode (xnd_development_…) = simulasi, tanpa uang sungguhan.
 */
class XenditGateway implements PaymentGateway
{
    private const STATUSES = [
        'ACTIVE' => self::STATUS_PENDING,
        'COMPLETED' => self::STATUS_PAID,
        'EXPIRED' => self::STATUS_EXPIRED,
        'CANCELED' => self::STATUS_CANCELED,
    ];

    public function __construct(
        private readonly string $url,
        private readonly ?string $secretKey,
        private readonly ?string $callbackToken,
        private readonly ?string $returnUrl = null,
        private readonly int $timeout = 15,
    ) {}

    public function name(): string
    {
        return 'xendit';
    }

    public function createCheckout(VoucherOrder $order, User $member): array
    {
        // Xendit hanya menerima URL kembali HTTPS; selama uji coba di laptop dikosongkan.
        $return = $this->returnUrl && str_starts_with($this->returnUrl, 'https://') ? $this->returnUrl : null;

        $response = $this->send(fn (PendingRequest $http) => $http->post('/sessions', array_filter([
            'reference_id' => $order->reference,
            'session_type' => 'PAY',
            'mode' => 'PAYMENT_LINK',
            'amount' => $order->amount,
            'currency' => 'IDR',
            'country' => 'ID',
            'capture_method' => 'AUTOMATIC',
            'description' => Str::limit("Voucher: {$order->title}", 990),
            'expires_at' => $order->expires_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'metadata' => ['order_reference' => $order->reference, 'member_code' => $member->member_code],
            'success_return_url' => $return,
            'cancel_return_url' => $return,
        ], fn ($value) => $value !== null)));

        $sessionId = $response->json('payment_session_id');
        $url = $response->json('payment_link_url');

        if (! $sessionId || ! $url) {
            Log::error('[Payment:xendit] respons sesi tanpa ID/URL', ['body' => $response->body()]);

            throw new PaymentException('Halaman pembayaran tidak bisa dibuat.');
        }

        return ['session_id' => $sessionId, 'url' => $url];
    }

    public function status(string $sessionId): string
    {
        $status = $this->send(fn (PendingRequest $http) => $http->get('/sessions/'.rawurlencode($sessionId)))->json('status');

        return self::STATUSES[$status] ?? self::STATUS_PENDING;
    }

    public function verifyWebhook(Request $request): bool
    {
        return filled($this->callbackToken)
            && hash_equals((string) $this->callbackToken, (string) $request->header('x-callback-token'));
    }

    public function webhookTarget(array $payload): array
    {
        return [
            'session_id' => data_get($payload, 'data.payment_session_id') ?? data_get($payload, 'payment_session_id'),
            'reference' => data_get($payload, 'data.reference_id') ?? data_get($payload, 'reference_id'),
        ];
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        if (blank($this->secretKey)) {
            Log::error('[Payment:xendit] XENDIT_SECRET_KEY kosong.');

            // Nama variabel .env hanya ditampilkan saat pengembangan, tidak ke pelanggan.
            throw new PaymentException(app()->environment(['local', 'testing'])
                ? 'Pembayaran belum dikonfigurasi (XENDIT_SECRET_KEY di backend/.env masih kosong).'
                : 'Pembayaran online sedang tidak tersedia.');
        }

        $http = Http::baseUrl(rtrim($this->url, '/'))
            ->connectTimeout(5)
            ->timeout($this->timeout)
            // TLS tetap diverifikasi; CaBundle dipakai karena PHP di Windows sering tanpa bundel CA.
            ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
            ->acceptJson()
            ->withBasicAuth((string) $this->secretKey, '');

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            Log::error("[Payment:xendit] tidak bisa terhubung: {$e->getMessage()}");

            throw new PaymentException('Layanan pembayaran tidak bisa dihubungi. Coba lagi sebentar.', previous: $e);
        }

        if ($response->failed()) {
            Log::warning("[Payment:xendit] HTTP {$response->status()}: {$response->body()}");

            throw new PaymentException(match ($response->json('error_code')) {
                'INVALID_API_KEY' => 'Secret key Xendit tidak valid.',
                default => 'Layanan pembayaran menolak permintaan ('.($response->json('error_code') ?? 'HTTP '.$response->status()).').',
            });
        }

        return $response;
    }
}
