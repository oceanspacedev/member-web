<?php

namespace App\Http\Resources;

use App\Models\Outlet;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Outlet */
class OutletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'brand' => new BrandResource($this->whenLoaded('brand')),
            'address' => $this->address,
            'city' => $this->city,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'distance_km' => $this->distance_km ?? null,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'opening_hours' => $this->opening_hours,
            'status' => $this->openStatus(),
            'maps_url' => $this->maps_url
                ?? ($this->latitude !== null ? "https://www.google.com/maps/search/?api=1&query={$this->latitude},{$this->longitude}" : null),
            'menu_url' => $this->menu_url,
            'photo_url' => Media::url($this->photo_path),
        ];
    }
}
