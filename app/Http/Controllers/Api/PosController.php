<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PointTransactionResource;
use App\Http\Resources\VoucherResource;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Voucher;
use App\Services\LoyaltyService;
use App\Services\MemberQr;
use App\Services\VoucherQr;
use App\Services\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Endpoint kasir. Fase 1 dipakai lewat mode Kasir di aplikasi;
 * Fase 3 endpoint yang sama dipanggil langsung oleh sistem POS.
 */
class PosController extends Controller
{
    public function scan(Request $request, MemberQr $qr, LoyaltyService $loyalty): JsonResponse
    {
        $request->validate(['qr' => ['required', 'string', 'max:200']]);
        $member = $qr->resolve($request->string('qr'));

        return response()->json([
            'member' => [
                'name' => $member->name,
                'member_code' => $member->member_code,
            ],
            'loyalty' => $loyalty->summary($member),
        ]);
    }

    public function earn(Request $request, MemberQr $qr, LoyaltyService $loyalty): JsonResponse
    {
        $data = $request->validate([
            'qr' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'integer', 'min:1000', 'max:1000000000'],
            'reference' => ['required', 'string', 'max:64'],
            'outlet_id' => ['nullable', 'integer'],
        ]);

        $member = $qr->resolve($data['qr']);
        $transaction = $loyalty->earn($member, $this->outlet($request), $data['amount'], $data['reference'], $request->user());

        return $this->result($transaction, $loyalty, $member);
    }

    public function redeem(Request $request, MemberQr $qr, LoyaltyService $loyalty): JsonResponse
    {
        $data = $request->validate([
            'qr' => ['required', 'string', 'max:200'],
            'points' => ['required', 'integer', 'min:100'],
            'reference' => ['required', 'string', 'max:64'],
            'outlet_id' => ['nullable', 'integer'],
        ]);

        $member = $qr->resolve($data['qr']);
        $transaction = $loyalty->redeem($member, $this->outlet($request), $data['points'], $data['reference'], $request->user());

        return $this->result($transaction, $loyalty, $member);
    }

    /**
     * Pratinjau voucher yang dipindai: milik siapa, nilainya, dan apakah berlaku di outlet ini.
     */
    public function voucherCheck(Request $request, VoucherQr $qr, VoucherService $vouchers): JsonResponse
    {
        $request->validate([
            'qr' => ['required', 'string', 'max:200'],
            'outlet_id' => ['nullable', 'integer'],
        ]);

        $voucher = $this->voucher($request->string('qr'), $qr, $vouchers);
        $problem = null;

        try {
            $vouchers->ensureUsable($voucher, $this->outlet($request));
        } catch (ValidationException $e) {
            $problem = collect($e->errors())->flatten()->first();
        }

        return response()->json([
            'voucher' => new VoucherResource($voucher),
            'member' => ['name' => $voucher->user->name, 'member_code' => $voucher->user->member_code],
            'usable' => $problem === null,
            'problem' => $problem,
        ]);
    }

    /**
     * Pakai voucher: potong tagihan, lalu poin pemilik voucher dihitung dari sisa yang dibayar.
     */
    public function voucherRedeem(Request $request, VoucherQr $qr, VoucherService $vouchers, LoyaltyService $loyalty): JsonResponse
    {
        $data = $request->validate([
            'qr' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'integer', 'min:1000', 'max:1000000000'],
            'reference' => ['required', 'string', 'max:64'],
            'outlet_id' => ['nullable', 'integer'],
        ]);

        $voucher = $this->voucher($data['qr'], $qr, $vouchers);
        $result = $vouchers->redeem($voucher, $this->outlet($request), $data['amount'], $data['reference'], $request->user());
        $used = $result['voucher'];

        return response()->json([
            'voucher' => new VoucherResource($used->load(['brand', 'outlet'])),
            'member' => ['name' => $used->user->name, 'member_code' => $used->user->member_code],
            'bill_amount' => $used->bill_amount,
            'discount' => $used->discount,
            'pay_amount' => $used->bill_amount - $used->discount,
            'transaction' => $result['earn'] ? new PointTransactionResource($result['earn']->load('outlet.brand')) : null,
            'loyalty' => $loyalty->summary($used->user->fresh()),
        ]);
    }

    /**
     * Voucher dari QR lama (LCV1....) atau dari kode yang diketik kasir (ABCDE-FGHJK).
     */
    private function voucher(string $input, VoucherQr $qr, VoucherService $vouchers): Voucher
    {
        if (str_starts_with(trim($input), VoucherQr::PREFIX.'.')) {
            return $qr->resolve($input);
        }

        return $vouchers->findByCode($input)?->load('user')
            ?? throw ValidationException::withMessages(['qr' => 'Kode voucher tidak ditemukan.']);
    }

    /**
     * Kasir terikat ke satu outlet; admin boleh memilih outlet.
     */
    private function outlet(Request $request): Outlet
    {
        $cashier = $request->user();
        $id = $cashier->role === User::ROLE_ADMIN ? ($request->input('outlet_id') ?? $cashier->outlet_id) : $cashier->outlet_id;

        $outlet = $id ? Outlet::find($id) : null;

        if (! $outlet) {
            throw ValidationException::withMessages(['outlet_id' => 'Akun kasir belum terhubung ke outlet.']);
        }

        return $outlet;
    }

    private function result($transaction, LoyaltyService $loyalty, User $member): JsonResponse
    {
        return response()->json([
            'transaction' => new PointTransactionResource($transaction->load('outlet.brand')),
            'loyalty' => $loyalty->summary($member->fresh()),
        ], $transaction->wasRecentlyCreated ? 201 : 200);
    }
}
