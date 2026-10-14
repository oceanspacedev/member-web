<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VoucherOrderResource;
use App\Models\Promo;
use App\Models\VoucherOrder;
use App\Services\Payment\PaymentGateway;
use App\Services\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Beli voucher dengan uang lewat payment gateway. */
class VoucherOrderController extends Controller
{
    public function store(Request $request, Promo $promo, VoucherService $vouchers): JsonResponse
    {
        $order = $vouchers->startPurchase($request->user(), $promo);

        return response()->json(['order' => new VoucherOrderResource($order)], $order->wasRecentlyCreated ? 201 : 200);
    }

    /** Aplikasi mengecek berkala setelah membuka halaman bayar; status diambil langsung dari gateway. */
    public function show(Request $request, VoucherOrder $order, VoucherService $vouchers): VoucherOrderResource
    {
        abort_unless($order->user_id === $request->user()->id, 404);

        $order = $vouchers->refreshOrder($order);

        return new VoucherOrderResource($order->load(['voucher.brand', 'voucher.promo']));
    }

    /**
     * Webhook gateway. Isi payload hanya dipakai untuk menemukan tagihannya;
     * status lunas selalu dicek ulang ke gateway sebelum voucher diterbitkan.
     */
    public function webhook(Request $request, PaymentGateway $gateway, VoucherService $vouchers): JsonResponse
    {
        abort_unless($gateway->verifyWebhook($request), 403);

        $target = $gateway->webhookTarget($request->all());
        $order = VoucherOrder::query()
            ->when($target['session_id'], fn ($q, $id) => $q->where('gateway_session_id', $id), fn ($q) => $q->where('reference', $target['reference']))
            ->first();

        if ($order) {
            $vouchers->refreshOrder($order, force: true);
        }

        // Selalu 200 agar gateway tidak mengulang kiriman untuk tagihan yang memang tidak kita kenal.
        return response()->json(['received' => true]);
    }
}
