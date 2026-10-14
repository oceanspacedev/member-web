<?php

namespace App\Http\Resources;

use App\Models\Brand;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Brand */
class BrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'category' => $this->category,
            'category_label' => $this->categoryLabel(),
            'legal_entity' => $this->legal_entity,
            'color' => $this->color,
            'logo_url' => Media::url($this->logo_path),
            'description' => $this->description,
            'outlets_count' => $this->whenCounted('outlets'),
        ];
    }
}
