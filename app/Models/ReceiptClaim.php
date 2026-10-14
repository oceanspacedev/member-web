<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Struk belanja yang difoto member untuk mendapat poin. Member cukup memfoto; sistem membaca
 * outlet, nomor struk, tanggal, dan total dari foto, lalu memutuskan sendiri. Hanya struk yang
 * meragukan (decision = needs_review) yang diperiksa admin.
 */
#[Fillable(['user_id', 'outlet_id', 'photo_path', 'photo_hash', 'photo_taken_at'])]
class ReceiptClaim extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING => 'Menunggu',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_REJECTED => 'Ditolak',
    ];

    protected $attributes = ['status' => self::STATUS_PENDING];

    /** Keputusan sistem; null = belum selesai diperiksa sistem. */
    public const DECISION_AUTO_APPROVED = 'auto_approved';

    public const DECISION_AUTO_REJECTED = 'auto_rejected';

    public const DECISION_NEEDS_REVIEW = 'needs_review';

    public const DECISIONS = [
        self::DECISION_AUTO_APPROVED => 'Disetujui sistem',
        self::DECISION_AUTO_REJECTED => 'Ditolak sistem',
        self::DECISION_NEEDS_REVIEW => 'Perlu diperiksa',
    ];

    /** Disk privat: struk bisa memuat data pribadi, jadi tidak disajikan publik. */
    public const DISK = 'local';

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'purchased_at' => 'date',
            'reviewed_at' => 'datetime',
            'photo_taken_at' => 'datetime',
            'verified_at' => 'datetime',
            'reading' => 'array',
            'flags' => 'array',
        ];
    }

    /** Nomor struk dibandingkan tanpa memedulikan spasi dan huruf besar/kecil. */
    public static function normalizeNumber(string $number): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($number)) ?? '');
    }

    // Termasuk akun yang sudah dihapus agar riwayat tetap tampil.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PointTransaction::class, 'point_transaction_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Sistem masih membaca struk (biasanya beberapa detik). */
    public function isChecking(): bool
    {
        return $this->isPending() && $this->verified_at === null;
    }
}
