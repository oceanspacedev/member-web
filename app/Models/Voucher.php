<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['promo_id', 'user_id', 'code', 'pos_outlet_id', 'source', 'period', 'title', 'brand_id', 'value', 'min_spend', 'points_spent', 'paid_amount', 'expires_at'])]
class Voucher extends Model
{
    public const SOURCE_CLAIM = 'claim';

    public const SOURCE_PURCHASE = 'purchase';

    public const SOURCE_BIRTHDAY = 'birthday';

    /** Dibeli dengan uang lewat payment gateway. */
    public const SOURCE_PAID = 'paid';

    public const SOURCES = [
        self::SOURCE_CLAIM => 'Klaim gratis',
        self::SOURCE_PURCHASE => 'Tukar poin',
        self::SOURCE_PAID => 'Dibeli',
        self::SOURCE_BIRTHDAY => 'Ulang tahun',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_USED = 'used';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'Aktif',
        self::STATUS_USED => 'Terpakai',
        self::STATUS_EXPIRED => 'Kedaluwarsa',
    ];

    /** Tanpa huruf/angka yang mirip (0/O, 1/I/L) agar mudah dibacakan ke kasir. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'min_spend' => 'integer',
            'points_spent' => 'integer',
            'paid_amount' => 'integer',
            'bill_amount' => 'integer',
            'discount' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public static function generateCode(): string
    {
        do {
            $code = collect(range(1, 10))
                ->map(fn () => self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)])
                ->implode('');
        } while (static::where('code', $code)->exists());

        return $code;
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    // Termasuk akun yang sudah dihapus agar riwayat tetap tampil ("Akun dihapus").
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /** API key POS pihak ketiga yang memakai voucher ini (null = dipakai lewat kasir Space Club). */
    public function partnerKey(): BelongsTo
    {
        return $this->belongsTo(PartnerKey::class);
    }

    public function status(): string
    {
        return match (true) {
            $this->used_at !== null => self::STATUS_USED,
            $this->expires_at->isPast() => self::STATUS_EXPIRED,
            default => self::STATUS_ACTIVE,
        };
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    public function scopeHistory(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNotNull('used_at')->orWhere('expires_at', '<=', now()));
    }

    /** Kode untuk dibacakan: ABCDE-FGHJK. */
    public function displayCode(): string
    {
        // Kode dari POS ditampilkan persis seperti di POS, agar kasir mengetiknya tanpa tanda hubung tambahan.
        if ($this->pos_outlet_id) {
            return $this->code;
        }

        return implode('-', str_split($this->code, 5));
    }

    /** Outlet yang POS-nya mengenali kode ini (voucher dari kode impor POS); null = kode Space Club. */
    public function posOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'pos_outlet_id');
    }
}
