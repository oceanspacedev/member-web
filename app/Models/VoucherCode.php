<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kode voucher yang dibuat di POS outlet dan diimpor ke sebuah promo. Satu kode untuk satu member;
 * kasir memasukkannya di POS outlet itu, dan POS yang memotong tagihan.
 */
#[Fillable(['promo_id', 'outlet_id', 'code', 'pos_title', 'value', 'starts_on', 'expires_on', 'max_uses', 'used_count'])]
class VoucherCode extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_USED = 'used';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_AVAILABLE => 'Tersedia',
        self::STATUS_ASSIGNED => 'Dipegang member',
        self::STATUS_USED => 'Terpakai',
        self::STATUS_EXPIRED => 'Kedaluwarsa',
    ];

    protected $attributes = ['max_uses' => 1, 'used_count' => 0];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'starts_on' => 'date',
            'expires_on' => 'date',
            'max_uses' => 'integer',
            'used_count' => 'integer',
        ];
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** Kode yang bisa dibagikan sekarang: belum dipegang member, belum terpakai di POS, sedang berlaku. */
    public function scopeAvailable(Builder $query): void
    {
        $today = now(config('loyalty.timezone'))->toDateString();

        $query->whereNull('voucher_id')
            ->whereColumn('used_count', '<', 'max_uses')
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $today));
    }

    public function status(): string
    {
        if ($this->used_count >= $this->max_uses || $this->voucher?->used_at) {
            return self::STATUS_USED;
        }
        if ($this->voucher_id) {
            return self::STATUS_ASSIGNED;
        }
        if ($this->expires_on && $this->expires_on->toDateString() < now(config('loyalty.timezone'))->toDateString()) {
            return self::STATUS_EXPIRED;
        }

        return self::STATUS_AVAILABLE;
    }
}
