<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'outlet_id', 'cashier_id', 'type', 'amount', 'points', 'balance_after', 'reference', 'description'])]
class PointTransaction extends Model
{
    public const TYPE_EARN = 'earn';

    public const TYPE_REDEEM = 'redeem';

    public const TYPE_ADJUST = 'adjust';

    /** Poin ditukar dengan voucher dari katalog promo. */
    public const TYPE_VOUCHER = 'voucher';

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'points' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    // Termasuk akun yang sudah dihapus agar riwayat tetap tampil ("Akun dihapus").
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
