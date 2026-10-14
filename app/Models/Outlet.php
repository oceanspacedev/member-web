<?php

namespace App\Models;

use App\Models\Concerns\DeletesReplacedMedia;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['brand_id', 'name', 'address', 'city', 'latitude', 'longitude', 'phone', 'whatsapp', 'opening_hours', 'maps_url', 'photo_path', 'menu_url', 'is_active'])]
class Outlet extends Model
{
    use DeletesReplacedMedia;

    protected static function mediaColumns(): array
    {
        return ['photo_path'];
    }

    /** Urutan hari mengikuti Carbon::dayOfWeek (0 = Minggu). */
    public const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'opening_hours' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Jarak dalam kilometer (haversine). Null bila koordinat belum diisi.
     */
    public function distanceTo(?float $lat, ?float $lng): ?float
    {
        if ($lat === null || $lng === null || $this->latitude === null || $this->longitude === null) {
            return null;
        }

        $dLat = deg2rad($this->latitude - $lat);
        $dLng = deg2rad($this->longitude - $lng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($this->latitude)) * sin($dLng / 2) ** 2;

        return round(6371 * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }

    /**
     * @return array{open: bool, opens_at: ?string, closes_at: ?string}
     */
    public function openStatus(?Carbon $at = null): array
    {
        // Jam buka ditulis dalam waktu lokal outlet, sedangkan aplikasi berjalan dalam UTC.
        $at = ($at ?? now())->copy()->setTimezone(config('loyalty.timezone'));
        $range = $this->opening_hours[self::DAYS[$at->dayOfWeek]] ?? null;

        if (! $range || ! str_contains($range, '-')) {
            return ['open' => false, 'opens_at' => null, 'closes_at' => null];
        }

        [$opens, $closes] = array_map('trim', explode('-', $range, 2));
        $time = $at->format('H:i');

        return ['open' => $time >= $opens && $time < $closes, 'opens_at' => $opens, 'closes_at' => $closes];
    }
}
