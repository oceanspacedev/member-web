<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Http\Resources\PartnerVoucherResource;
use App\Models\PartnerKey;
use App\Models\Voucher;
use App\Services\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * API partner POS: Space Club sebagai validator voucher member.
 *
 * Alur di kasir: member menyebutkan/menunjukkan kode voucher → POS memanggil `check` →
 * bila valid, POS memasang potongan lalu memanggil `redeem` dengan nomor struk.
 * Bila transaksi di POS dibatalkan, panggil `cancel` agar voucher bisa dipakai lagi.
 *
 * @tags Partner POS
 */
class VoucherController extends Controller
{
    public function __construct(private VoucherService $vouchers) {}

    /**
     * Info outlet pemilik API key
     *
     * Untuk mengetes API key: voucher selalu dicek terhadap outlet ini.
     */
    public function outlet(Request $request): JsonResponse
    {
        $key = $this->key($request);

        return response()->json([
            'key_name' => $key->name,
            'outlet' => [
                'id' => $key->outlet->id,
                'name' => $key->outlet->name,
                'brand' => $key->outlet->brand?->name,
            ],
        ]);
    }

    /**
     * Cek voucher
     *
     * Tidak mengubah apa pun. Kirim `amount` (total belanja) untuk ikut mengecek minimal belanja
     * dan mendapat besar potongan. `valid: false` disertai alasan di `problem` untuk ditampilkan ke kasir.
     */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            /** Kode voucher dari member, boleh dengan/tanpa tanda hubung. @example ABCDE-FGHJK */
            'code' => ['required', 'string', 'max:32'],
            /** Total belanja (rupiah) sebelum potongan. @example 150000 */
            'amount' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
        ]);

        $voucher = $this->find($data['code']);
        $amount = $data['amount'] ?? null;
        $problem = null;

        try {
            $this->vouchers->ensureUsable($voucher, $this->key($request)->outlet, $amount);
        } catch (ValidationException $e) {
            $problem = collect($e->errors())->flatten()->first();
        }

        return response()->json([
            'valid' => $problem === null,
            /** Alasan voucher tidak bisa dipakai, untuk ditampilkan ke kasir. */
            'problem' => $problem,
            /** Potongan untuk `amount` yang dikirim; null bila voucher tidak valid atau `amount` tidak dikirim. */
            'discount' => $amount === null || $problem !== null ? null : min($voucher->value, $amount),
            'voucher' => new PartnerVoucherResource($voucher),
        ]);
    }

    /**
     * Pakai voucher
     *
     * Menandai voucher terpakai untuk satu struk. Aman diulang: request ulang dengan kode dan
     * nomor struk yang sama mengembalikan hasil yang sama (mis. saat koneksi POS terputus).
     * Satu struk hanya bisa memakai satu voucher.
     */
    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate([
            /** @example ABCDE-FGHJK */
            'code' => ['required', 'string', 'max:32'],
            /** Total belanja (rupiah) sebelum potongan. @example 150000 */
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            /** Nomor struk/transaksi di POS, unik per outlet. @example INV-2026-000123 */
            'reference' => ['required', 'string', 'max:64'],
        ]);

        $voucher = $this->vouchers->redeemForPartner($this->find($data['code']), $this->key($request), $data['amount'], $data['reference']);

        return response()->json([
            'voucher' => new PartnerVoucherResource($voucher),
            'bill_amount' => $voucher->bill_amount,
            /** Potongan yang dipasang di POS (rupiah). */
            'discount' => $voucher->discount,
            /** Yang dibayar member setelah potongan. */
            'pay_amount' => $voucher->bill_amount - $voucher->discount,
        ]);
    }

    /**
     * Batalkan pemakaian voucher
     *
     * Untuk transaksi yang dibatalkan/di-void di POS: voucher kembali aktif dan bisa dipakai lagi.
     * Hanya untuk struk dan outlet yang sama, maksimal 24 jam setelah dipakai.
     */
    public function cancel(Request $request): JsonResponse
    {
        $data = $request->validate([
            /** @example ABCDE-FGHJK */
            'code' => ['required', 'string', 'max:32'],
            /** Nomor struk yang dipakai saat `redeem`. @example INV-2026-000123 */
            'reference' => ['required', 'string', 'max:64'],
        ]);

        $voucher = $this->vouchers->cancelForPartner($this->find($data['code']), $this->key($request), $data['reference']);

        return response()->json(['voucher' => new PartnerVoucherResource($voucher)]);
    }

    private function find(string $code): Voucher
    {
        return $this->vouchers->findByCode($code) ?? abort(404, 'Kode voucher tidak ditemukan.');
    }

    private function key(Request $request): PartnerKey
    {
        return $request->attributes->get('partner_key');
    }
}
