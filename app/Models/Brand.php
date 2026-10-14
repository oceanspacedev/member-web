<?php

namespace App\Models;

use App\Models\Concerns\DeletesReplacedMedia;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'category', 'legal_entity', 'color', 'logo_path', 'description'])]
class Brand extends Model
{
    use DeletesReplacedMedia;

    protected static function mediaColumns(): array
    {
        return ['logo_path'];
    }

    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class);
    }

    public function promos(): HasMany
    {
        return $this->hasMany(Promo::class);
    }

    public function categoryLabel(): string
    {
        return config('loyalty.brand_categories.'.$this->category, $this->category);
    }
}
