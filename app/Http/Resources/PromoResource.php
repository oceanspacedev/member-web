<?php

namespace App\Http\Resources;

use App\Models\Promo;
use App\Models\User;
use App\Services\LoyaltyService;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Promo */
class PromoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'highlight' => $this->highlight,
            'highlight_caption' => $this->highlight_caption,
            'description' => $this->description,
            'terms' => $this->terms,
            'image_url' => Media::url($this->image_path),
            'is_universal' => $this->brand_id === null,
            'brand' => new BrandResource($this->whenLoaded('brand')),
            'min_tier' => $this->min_tier,
            'min_tier_label' => $this->min_tier ? config("loyalty.tiers.{$this->min_tier}.label") : null,
            'starts_at' => $this->starts_at->toDateString(),
            'ends_at' => $this->ends_at->toDateString(),
            'voucher' => $this->isAcquirable() ? [
                'value' => $this->voucher_value,
                'min_spend' => $this->min_spend,
                'price_points' => $this->type === Promo::TYPE_SALE ? $this->price_points : null,
                'price_rupiah' => $this->type === Promo::TYPE_SALE ? $this->price_rupiah : null,
                'open_order_id' => $this->my_open_order_id ?? null,
                'quota_left' => $this->quotaLeft(),
                'valid_days' => $this->valid_days,
                'state' => $this->voucherState($request->user('sanctum')),
            ] : null,
        ];
    }

    /**
     * available · claimed (batas per member tercapai) · pending (tagihan belum dibayar) · locked (tier belum cukup) · sold_out
     */
    private function voucherState(?User $user): string
    {
        if ($user && isset($this->my_vouchers_count) && $this->my_vouchers_count >= $this->per_member_limit) {
            return 'claimed';
        }
        if ($user && ($this->my_open_order_id ?? null)) {
            return 'pending';
        }
        if ($this->quotaLeft() === 0) {
            return 'sold_out';
        }
        if ($user && ! app(LoyaltyService::class)->meetsTier($user->tier, $this->min_tier)) {
            return 'locked';
        }

        return 'available';
    }
}
