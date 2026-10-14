<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VoucherResource;
use App\Models\Promo;
use App\Models\Voucher;
use App\Services\LoyaltyService;
use App\Services\VoucherQr;
use App\Services\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Voucher Saya: voucher milik member yang sedang login. */
class VoucherController extends Controller
{
    public function index(Request $request, VoucherService $vouchers): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', Rule::in(['active', 'history'])]]);
        $vouchers->issueBirthdayVouchers($request->user());

        $query = $request->user()->vouchers()->with(['brand', 'promo', 'outlet']);

        $list = $request->input('status') === 'history'
            ? $query->history()->latest('updated_at')->limit(50)->get()
            : $query->active()->orderBy('expires_at')->get();

        return VoucherResource::collection($list);
    }

    public function show(Request $request, Voucher $voucher): VoucherResource
    {
        $this->ensureOwner($request, $voucher);

        return new VoucherResource($voucher->load(['brand', 'promo', 'outlet']));
    }

    public function qr(Request $request, Voucher $voucher, VoucherQr $qr): JsonResponse
    {
        $this->ensureOwner($request, $voucher);
        abort_if($voucher->status() !== Voucher::STATUS_ACTIVE, 422, 'Voucher ini sudah tidak aktif.');

        return response()->json($qr->issue($voucher));
    }

    /** Klaim gratis atau tukar poin dari katalog promo. */
    public function store(Request $request, Promo $promo, VoucherService $vouchers, LoyaltyService $loyalty): JsonResponse
    {
        $voucher = $vouchers->acquire($request->user(), $promo);

        return response()->json([
            'voucher' => new VoucherResource($voucher->load(['brand', 'promo'])),
            'loyalty' => $loyalty->summary($request->user()->fresh()),
        ], 201);
    }

    private function ensureOwner(Request $request, Voucher $voucher): void
    {
        // 404, bukan 403: tidak membocorkan bahwa voucher dengan ID itu ada.
        abort_unless($voucher->user_id === $request->user()->id, 404);
    }
}
