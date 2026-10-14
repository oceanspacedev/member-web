<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * API key POS pihak ketiga, terikat ke satu outlet. Voucher yang divalidasi/dipakai
 * lewat key ini selalu dicek terhadap brand outlet tersebut.
 */
#[Fillable(['name', 'outlet_id'])]
class PartnerKey extends Model
{
    public const PREFIX = 'osk_';

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Buat key baru. Key utuh hanya dikembalikan di sini; yang disimpan hanya hash-nya.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, Outlet $outlet): array
    {
        $plain = self::PREFIX.Str::random(40);

        $key = new self(['name' => $name, 'outlet_id' => $outlet->id]);
        $key->forceFill(['key_prefix' => substr($plain, 0, 9), 'key_hash' => self::hash($plain)])->save();

        return [$key, $plain];
    }

    public static function findActive(string $plain): ?self
    {
        return self::active()->with('outlet.brand')->where('key_hash', self::hash($plain))->first();
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }
}
