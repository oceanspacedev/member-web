<?php

namespace App\Http\Resources;

use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Voucher untuk POS pihak ketiga: hanya yang dibutuhkan kasir, tanpa data pribadi member.
 *
 * @mixin Voucher
 */
class PartnerVoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tz = config('loyalty.timezone');

        return [
            /** Kode voucher, format tampilan ABCDE-FGHJK. */
            'code' => $this->displayCode(),
            'title' => $this->title,
            /** active | used | expired */
            'status' => $this->status(),
            /** Nilai potongan (rupiah). */
            'value' => $this->value,
            /** Minimal belanja (rupiah), 0 = tanpa minimal. */
            'min_spend' => $this->min_spend,
            /** Brand tempat voucher berlaku; null = semua brand Space Club. */
            'brand' => $this->brand?->name,
            'expires_at' => $this->expires_at->timezone($tz)->toIso8601String(),
            'used_at' => $this->used_at?->timezone($tz)->toIso8601String(),
            /** Nomor struk saat voucher dipakai. */
            'reference' => $this->reference,
        ];
    }
}
