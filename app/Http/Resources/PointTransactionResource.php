<?php

namespace App\Http\Resources;

use App\Models\PointTransaction;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PointTransaction */
class PointTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount' => $this->amount,
            'points' => $this->points,
            'balance_after' => $this->balance_after,
            'reference' => $this->reference,
            'description' => $this->description,
            'outlet' => $this->whenLoaded('outlet', fn () => $this->outlet ? [
                'id' => $this->outlet->id,
                'name' => $this->outlet->name,
                'brand' => $this->outlet->relationLoaded('brand') ? $this->outlet->brand->name : null,
                'color' => $this->outlet->relationLoaded('brand') ? $this->outlet->brand->color : null,
                'logo_url' => $this->outlet->relationLoaded('brand') ? Media::url($this->outlet->brand->logo_path) : null,
            ] : null),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
