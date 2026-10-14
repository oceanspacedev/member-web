<?php

namespace App\Http\Resources;

use App\Models\Voucher;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Voucher */
class VoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tz = config('loyalty.timezone');

        return [
            'id' => $this->id,
            'code' => $this->displayCode(),
            'title' => $this->title,
            'source' => $this->source,
            'source_label' => Voucher::SOURCES[$this->source] ?? $this->source,
            'status' => $this->status(),
            'value' => $this->value,
            'min_spend' => $this->min_spend,
            'points_spent' => $this->points_spent,
            'paid_amount' => $this->paid_amount,
            'brand' => $this->brand_id ? new BrandResource($this->whenLoaded('brand')) : null,
            'is_universal' => $this->brand_id === null,
            'terms' => $this->promo?->terms,
            'image_url' => Media::url($this->promo?->image_path),
            'expires_at' => $this->expires_at->timezone($tz)->toIso8601String(),
            'used_at' => $this->used_at?->timezone($tz)->toIso8601String(),
            'used_outlet' => $this->whenLoaded('outlet', fn () => $this->outlet?->name),
            'discount' => $this->discount,
            'created_at' => $this->created_at->timezone($tz)->toIso8601String(),
        ];
    }
}
