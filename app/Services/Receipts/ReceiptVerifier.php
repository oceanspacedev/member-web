<?php

namespace App\Services\Receipts;

use App\Models\Outlet;
use App\Models\PointTransaction;
use App\Models\ReceiptClaim;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Aturan keputusan struk, terpisah dari pembaca agar bisa diaudit dan dites.
 *
 * - Tolak otomatis: masalah yang pasti dan bisa diperbaiki member (bukan struk, tidak terbaca,
 *   terlalu lama, sudah pernah dapat poin). Alasan ditulis untuk member.
 * - Periksa admin: ada tanda kecurangan atau keraguan. Alasan ditulis untuk admin.
 * - Setujui otomatis: semua aturan lolos.
 */
class ReceiptVerifier
{
    public const UNREADABLE = 'Struk tidak terbaca jelas. Foto ulang seluruh struk dengan cahaya cukup; nomor struk, tanggal, dan total harus terlihat.';

    /**
     * @return array{decision: string, reason?: string, flags?: list<string>, outlet?: Outlet, number?: string, amount?: int, purchased?: CarbonImmutable}
     */
    public function decide(ReceiptClaim $claim, ReceiptReading $reading, ?ReceiptImage $image): array
    {
        $tz = config('loyalty.timezone');
        $cfg = config('loyalty.receipts');
        $sent = $claim->created_at->timezone($tz)->toImmutable();
        $confident = $reading->confidence >= $cfg['min_confidence'];

        if (! $reading->isReceipt) {
            return $this->reject('Foto ini bukan struk belanja. Foto struk dari kasir outlet Space Club.');
        }

        $purchased = $this->parseDate($reading->purchasedAt, $tz);
        $number = $reading->receiptNumber ? ReceiptClaim::normalizeNumber($reading->receiptNumber) : '';
        if (! $reading->legible || $number === '' || ! $reading->total || ! $purchased) {
            return $this->reject(self::UNREADABLE);
        }

        // Outlet dari struk; pilihan member (aplikasi lama) hanya dipakai bila struk tidak menyebutnya.
        $outlet = $reading->outletId ? Outlet::where('is_active', true)->find($reading->outletId) : null;
        $outlet ??= $claim->outlet;

        $maxAge = $cfg['max_age_days'];
        if ($purchased->startOfDay()->lt($sent->startOfDay()->subDays($maxAge)) && $confident) {
            return $this->reject("Struk lebih dari {$maxAge} hari tidak bisa diklaim. Kirim struk paling lambat {$maxAge} hari setelah belanja.");
        }

        if ($outlet && $this->alreadyEarned($outlet, $number)) {
            return $this->reject('Struk ini sudah pernah mendapat poin.');
        }

        $flags = [];

        if (! $outlet) {
            $flags[] = 'Outlet tidak dikenali dari struk'.($reading->merchantName ? " (tertulis: {$reading->merchantName})" : '').'.';
        }
        if ($reading->tamperSuspected || $reading->tamperSigns) {
            $flags[] = 'Tanda editan: '.($reading->tamperSigns ? implode('; ', $reading->tamperSigns) : 'terdeteksi').'.';
        }
        if ($reading->capture !== ReceiptReading::CAPTURE_PAPER) {
            $flags[] = match ($reading->capture) {
                ReceiptReading::CAPTURE_SCREENSHOT => 'Tangkapan layar, bukan foto kertas struk.',
                ReceiptReading::CAPTURE_SCREEN => 'Foto layar, bukan foto kertas struk.',
                ReceiptReading::CAPTURE_DIGITAL => 'Struk elektronik, bukan struk kertas.',
                default => 'Bukan foto kertas struk.',
            };
        }
        if (! $confident) {
            $flags[] = sprintf('Keyakinan baca rendah (%d%%).', (int) round($reading->confidence * 100));
        }
        if ($purchased->startOfDay()->gt($sent->startOfDay())) {
            $flags[] = 'Tanggal struk terbaca setelah tanggal kirim (mungkin salah baca hari/bulan).';
        } elseif ($purchased->startOfDay()->lt($sent->startOfDay()->subDays($maxAge))) {
            $flags[] = "Tanggal struk terbaca lebih dari {$maxAge} hari lalu (mungkin salah baca).";
        }
        if ($reading->total > $cfg['auto_approve_max_amount']) {
            $flags[] = 'Total di atas batas persetujuan otomatis (Rp'.number_format($cfg['auto_approve_max_amount'], 0, ',', '.').').';
        }
        if ($editor = $image?->editedWith()) {
            $flags[] = "Foto disimpan dengan aplikasi edit ({$editor}).";
        }
        $takenAt = $image?->takenAt($tz);
        if ($takenAt && strlen((string) $reading->purchasedAt) > 10 && $takenAt->lt($purchased->subMinutes(10))) {
            $flags[] = 'Foto diambil sebelum jam transaksi di struk.';
        }
        if ($outlet && $this->samePendingNumber($claim, $outlet, $number)) {
            $flags[] = 'Nomor struk sama dengan klaim lain yang sedang diproses.';
        }
        if ($outlet && $this->approvedToday($claim, $outlet, $purchased) >= $cfg['auto_per_outlet_per_day']) {
            $flags[] = 'Member sudah beberapa kali klaim di outlet ini pada hari yang sama.';
        }

        if ($flags) {
            return ['decision' => ReceiptClaim::DECISION_NEEDS_REVIEW, 'flags' => $flags];
        }

        return [
            'decision' => ReceiptClaim::DECISION_AUTO_APPROVED,
            'outlet' => $outlet,
            'number' => $number,
            'amount' => $reading->total,
            'purchased' => $purchased,
        ];
    }

    /** @return array{decision: string, reason: string} */
    private function reject(string $reason): array
    {
        return ['decision' => ReceiptClaim::DECISION_AUTO_REJECTED, 'reason' => $reason];
    }

    private function parseDate(?string $value, string $tz): ?CarbonImmutable
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat(strlen($value) > 10 ? 'Y-m-d H:i' : 'Y-m-d|', $value, $tz) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function alreadyEarned(Outlet $outlet, string $number): bool
    {
        return ReceiptClaim::where('outlet_id', $outlet->id)->where('receipt_number', $number)->where('status', ReceiptClaim::STATUS_APPROVED)->exists()
            || PointTransaction::where('outlet_id', $outlet->id)->where('type', PointTransaction::TYPE_EARN)->where('reference', $number)->exists();
    }

    /** Klaim lain (milik siapa pun) dengan nomor yang sama yang belum selesai. */
    private function samePendingNumber(ReceiptClaim $claim, Outlet $outlet, string $number): bool
    {
        return ReceiptClaim::whereKeyNot($claim->id)
            ->where('status', ReceiptClaim::STATUS_PENDING)
            ->where(fn ($q) => $q->where('outlet_id', $outlet->id)->orWhereNull('outlet_id'))
            ->where('receipt_number', $number)
            ->exists();
    }

    private function approvedToday(ReceiptClaim $claim, Outlet $outlet, CarbonImmutable $purchased): int
    {
        return ReceiptClaim::where('user_id', $claim->user_id)
            ->where('outlet_id', $outlet->id)
            ->where('status', ReceiptClaim::STATUS_APPROVED)
            ->whereDate('purchased_at', $purchased->toDateString())
            ->count();
    }
}
