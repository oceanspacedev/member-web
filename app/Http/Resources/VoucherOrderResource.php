<?php

namespace App\Http\Resources;

use App\Models\VoucherOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VoucherOrder */
class VoucherOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tz = config('loyalty.timezone');

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'amount' => $this->amount,
            'status' => $this->status,
            'status_label' => VoucherOrder::STATUSES[$this->status] ?? $this->status,
            // Hanya selama masih bisa dibayar.
            'payment_url' => $this->isPending() ? $this->payment_url : null,
            'expires_at' => $this->expires_at->timezone($tz)->toIso8601String(),
            'paid_at' => $this->paid_at?->timezone($tz)->toIso8601String(),
            'voucher' => $this->voucher_id ? new VoucherResource($this->whenLoaded('voucher')) : null,
        ];
    }
}
