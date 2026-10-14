<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['reference', 'user_id', 'promo_id', 'title', 'snapshot', 'amount', 'gateway', 'expires_at'])]
class VoucherOrder extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELED = 'canceled';

    public const STATUSES = [
        self::STATUS_PENDING => 'Menunggu bayar',
        self::STATUS_PAID => 'Lunas',
        self::STATUS_EXPIRED => 'Kedaluwarsa',
        self::STATUS_CANCELED => 'Dibatalkan',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'amount' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    public static function newReference(): string
    {
        return 'VO-'.Str::upper((string) Str::ulid());
    }

    // Termasuk akun yang sudah dihapus agar riwayat tetap tampil ("Akun dihapus").
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** Tagihan yang masih bisa dibayar — ikut dihitung sebagai "dipesan" untuk kuota & batas per member. */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING)->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
