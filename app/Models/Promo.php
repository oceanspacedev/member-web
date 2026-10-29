<?php

namespace App\Models;

use App\Models\Concerns\DeletesReplacedMedia;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'brand_id', 'pos_outlet_id', 'type', 'title', 'highlight', 'highlight_caption', 'description', 'terms', 'image_path', 'starts_at', 'ends_at', 'is_active',
    'voucher_value', 'min_spend', 'price_points', 'price_rupiah', 'min_tier', 'quota', 'per_member_limit', 'valid_days',
])]
class Promo extends Model
{
    use DeletesReplacedMedia;

    /** Hanya informasi; member cukup datang ke outlet. */
    public const TYPE_INFO = 'info';

    /** Voucher gratis yang diklaim member dari katalog. */
    public const TYPE_CLAIM = 'claim';

    /** Voucher yang ditukar dengan poin. */
    public const TYPE_SALE = 'sale';

    /** Voucher otomatis di bulan ulang tahun member; tidak tampil di katalog. */
    public const TYPE_BIRTHDAY = 'birthday';

    public const TYPES = [
        self::TYPE_INFO => 'Info',
        self::TYPE_CLAIM => 'Klaim gratis',
        self::TYPE_SALE => 'Tukar poin',
        self::TYPE_BIRTHDAY => 'Ulang tahun',
    ];

    protected $attributes = [
        'type' => self::TYPE_INFO,
        'min_spend' => 0,
        'issued_count' => 0,
        'per_member_limit' => 1,
    ];

    protected static function mediaColumns(): array
    {
        return ['image_path'];
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'is_active' => 'boolean',
            'voucher_value' => 'integer',
            'min_spend' => 'integer',
            'price_points' => 'integer',
            'price_rupiah' => 'integer',
            'quota' => 'integer',
            'issued_count' => 'integer',
            'per_member_limit' => 'integer',
            'valid_days' => 'integer',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    /** Outlet yang POS-nya membuat kode voucher promo ini (null = kode dibuat Space Club). */
    public function posOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'pos_outlet_id');
    }

    /** Kode voucher yang diimpor dari POS outlet. */
    public function codes(): HasMany
    {
        return $this->hasMany(VoucherCode::class);
    }

    public function usesPosCodes(): bool
    {
        return $this->pos_outlet_id !== null;
    }

    public function scopeRunning(Builder $query): void
    {
        $today = now()->toDateString();

        $query->where('is_active', true)
            ->whereDate('starts_at', '<=', $today)
            ->whereDate('ends_at', '>=', $today);
    }

    /** Promo yang tampil di katalog aplikasi (voucher ulang tahun dikirim otomatis, bukan dipilih). */
    public function scopeInCatalog(Builder $query): void
    {
        $query->where('type', '!=', self::TYPE_BIRTHDAY);
    }

    /** Klaim gratis atau tukar poin: member mengambil voucher sendiri dari katalog. */
    public function isAcquirable(): bool
    {
        return in_array($this->type, [self::TYPE_CLAIM, self::TYPE_SALE], true);
    }

    public function issuesVouchers(): bool
    {
        return $this->type !== self::TYPE_INFO;
    }

    public function orders(): HasMany
    {
        return $this->hasMany(VoucherOrder::class);
    }

    /** Sisa kuota; tagihan yang belum dibayar (masih berlaku) ikut dihitung sebagai terpesan. */
    public function quotaLeft(): ?int
    {
        // Kode dari POS: kuota = kode yang masih bisa dibagikan.
        if ($this->usesPosCodes()) {
            $reserved = $this->open_orders_count ?? $this->orders()->open()->count();

            return max(0, $this->codes()->available()->count() - $reserved);
        }

        if ($this->quota === null) {
            return null;
        }

        $reserved = $this->open_orders_count ?? $this->orders()->open()->count();

        return max(0, $this->quota - $this->issued_count - $reserved);
    }
}
