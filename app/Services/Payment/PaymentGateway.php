<?php

namespace App\Services\Payment;

use App\Models\User;
use App\Models\VoucherOrder;
use Illuminate\Http\Request;

/**
 * Payment gateway untuk beli voucher dengan uang. Driver: xendit (lihat config loyalty.payment).
 * Ganti gateway = tambah satu kelas yang mengimplementasikan interface ini.
 */
interface PaymentGateway
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELED = 'canceled';

    public function name(): string;

    /**
     * Buat halaman bayar untuk tagihan ini.
     *
     * @return array{session_id: string, url: string}
     *
     * @throws PaymentException
     */
    public function createCheckout(VoucherOrder $order, User $member): array;

    /**
     * Status terbaru langsung dari gateway: pending | paid | expired | canceled.
     *
     * @throws PaymentException
     */
    public function status(string $sessionId): string;

    /** Webhook benar-benar dari gateway (token/tanda tangan cocok). */
    public function verifyWebhook(Request $request): bool;

    /**
     * Penanda tagihan di payload webhook. Isi payload tidak dipercaya untuk status;
     * status selalu dicek ulang lewat status().
     *
     * @return array{session_id: ?string, reference: ?string}
     */
    public function webhookTarget(array $payload): array;
}
