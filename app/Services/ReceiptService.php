<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\PointTransaction;
use App\Models\ReceiptClaim;
use App\Models\User;
use App\Services\Receipts\ReceiptImage;
use App\Services\Receipts\ReceiptReader;
use App\Services\Receipts\ReceiptReaderUnavailable;
use App\Services\Receipts\ReceiptVerifier;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Poin dari foto struk: outlet memakai POS pihak ketiga yang tidak kita kendalikan, jadi foto struk
 * adalah buktinya. Sistem yang membaca dan memutuskan (verify); admin hanya memeriksa struk yang
 * meragukan. Poin masuk dengan aturan yang sama dengan kasir.
 */
class ReceiptService
{
    public function __construct(
        private LoyaltyService $loyalty,
        private WhatsAppSender $whatsApp,
        private ReceiptReader $reader,
        private ReceiptVerifier $verifier,
    ) {}

    /**
     * Member cukup mengirim foto. Outlet dibaca dari struk; outlet_id dari aplikasi versi lama
     * hanya cadangan bila struk tidak menyebut outlet.
     */
    public function submit(User $member, UploadedFile $photo, ?int $outletId = null): ReceiptClaim
    {
        $tz = config('loyalty.timezone');
        $outlet = $outletId === null ? null : (Outlet::where('is_active', true)->find($outletId) ?? $this->fail('outlet_id', 'Outlet tidak ditemukan.'));

        $todayCount = ReceiptClaim::where('user_id', $member->id)->where('created_at', '>=', now($tz)->startOfDay())->count();
        if ($todayCount >= config('loyalty.receipts.daily_limit')) {
            $this->fail('photo', 'Batas kirim struk hari ini sudah tercapai. Coba lagi besok.');
        }

        $hash = hash_file('sha256', $photo->getRealPath());
        if (ReceiptClaim::where('photo_hash', $hash)->where('status', '!=', ReceiptClaim::STATUS_REJECTED)->exists()) {
            $this->fail('photo', 'Foto struk ini sudah pernah dikirim.');
        }

        return ReceiptClaim::create([
            'user_id' => $member->id,
            'outlet_id' => $outlet?->id,
            'photo_path' => $photo->store('receipts/'.now($tz)->format('Y/m'), ReceiptClaim::DISK),
            'photo_hash' => $hash,
        ]);
    }

    /**
     * Pemeriksaan sistem: baca foto, terapkan aturan, lalu setujui, tolak, atau serahkan ke admin.
     * Gagal baca (layanan mati, foto rusak) tidak pernah merugikan member: struk diserahkan ke admin.
     */
    public function verify(ReceiptClaim $claim): ReceiptClaim
    {
        if (! $claim->isChecking()) {
            return $claim;
        }

        $bytes = Storage::disk(ReceiptClaim::DISK)->get($claim->photo_path);
        $image = $bytes === null ? null : ReceiptImage::fromBytes($bytes);
        $claim->forceFill(['photo_taken_at' => $image?->takenAt(config('loyalty.timezone'))]);

        if (! $image) {
            return $this->needsReview($claim, ['Foto tidak bisa diproses otomatis.']);
        }

        try {
            $outlets = Outlet::with('brand')->where('is_active', true)->orderBy('id')->get();
            $reading = $this->reader->read($image->forReading(), 'image/jpeg', $outlets);
        } catch (ReceiptReaderUnavailable $e) {
            Log::warning("[Receipt] klaim #{$claim->id} tidak terbaca otomatis: {$e->getMessage()}");

            return $this->needsReview($claim, ['Pembacaan otomatis tidak tersedia; periksa manual.']);
        }

        $claim->forceFill([
            'reading' => $reading->toArray(),
            // Isian awal untuk admin bila perlu diperiksa; nilai final ditulis saat disetujui.
            'receipt_number' => $reading->receiptNumber ? ReceiptClaim::normalizeNumber($reading->receiptNumber) : null,
            'amount' => $reading->total,
            'outlet_id' => $reading->outletId && Outlet::where('is_active', true)->whereKey($reading->outletId)->exists() ? $reading->outletId : $claim->outlet_id,
        ]);

        $result = $this->verifier->decide($claim, $reading, $image);

        if ($result['decision'] === ReceiptClaim::DECISION_AUTO_REJECTED) {
            $claim->forceFill(['decision' => $result['decision'], 'verified_at' => now()])->save();

            return $this->reject($claim, null, $result['reason']);
        }

        if ($result['decision'] === ReceiptClaim::DECISION_NEEDS_REVIEW) {
            return $this->needsReview($claim, $result['flags']);
        }

        $claim->forceFill(['decision' => $result['decision'], 'verified_at' => now()])->save();

        try {
            return $this->approve($claim, null, $result['number'], $result['amount'], $result['purchased']->toDateString(), $result['outlet']->id);
        } catch (ValidationException $e) {
            // Mis. struk yang sama baru saja disetujui lewat jalur lain: serahkan ke admin.
            return $this->needsReview($claim->fresh(), [collect($e->errors())->flatten()->first()]);
        }
    }

    /** @param  list<string>  $flags */
    private function needsReview(ReceiptClaim $claim, array $flags): ReceiptClaim
    {
        $claim->forceFill([
            'decision' => ReceiptClaim::DECISION_NEEDS_REVIEW,
            'flags' => $flags,
            'verified_at' => now(),
        ])->save();

        return $claim;
    }

    /**
     * Setujui klaim dengan data struk (dibaca sistem, atau diperiksa admin; $reviewer null = sistem).
     * Poin masuk lewat LoyaltyService::earn (cashback sesuai tier, ikut menaikkan tier). Nomor struk
     * dipakai sebagai referensi, sehingga struk yang sama tidak bisa mendapat poin dua kali,
     * termasuk bila sudah tercatat lewat kasir.
     */
    public function approve(ReceiptClaim $claim, ?User $reviewer, string $receiptNumber, int $amount, string $purchasedAt, ?int $outletId = null): ReceiptClaim
    {
        $tz = config('loyalty.timezone');
        $number = ReceiptClaim::normalizeNumber($receiptNumber);
        $purchased = Carbon::parse($purchasedAt, $tz)->startOfDay();
        $maxAge = config('loyalty.receipts.max_age_days');

        // Batas umur dihitung dari saat struk dikirim, bukan saat admin memeriksa.
        if ($purchased->gt($claim->created_at->timezone($tz)->startOfDay())) {
            $this->fail('purchased_at', 'Tanggal belanja tidak boleh setelah struk dikirim.');
        }
        if ($purchased->lt($claim->created_at->timezone($tz)->startOfDay()->subDays($maxAge))) {
            $this->fail('purchased_at', "Struk lebih dari {$maxAge} hari sebelum dikirim tidak bisa diklaim.");
        }

        $claim = DB::transaction(function () use ($claim, $reviewer, $number, $amount, $purchased, $outletId) {
            $claim = ReceiptClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();

            if (! $claim->isPending()) {
                $this->fail('status', 'Klaim ini sudah diperiksa.');
            }
            if ($outletId !== null) {
                $claim->outlet()->associate(Outlet::where('is_active', true)->find($outletId) ?? $this->fail('outlet_id', 'Outlet tidak ditemukan.'));
            }
            if (! $claim->outlet) {
                $this->fail('outlet_id', 'Pilih outlet sesuai struk.');
            }
            $this->ensureNotClaimed($claim->outlet, $number);

            $transaction = $this->loyalty->earn($claim->user, $claim->outlet, $amount, $number, $reviewer);

            $claim->forceFill([
                'status' => ReceiptClaim::STATUS_APPROVED,
                'receipt_number' => $number,
                'amount' => $amount,
                'purchased_at' => $purchased->toDateString(),
                'reviewed_by' => $reviewer?->id,
                'reviewed_at' => now(),
                'point_transaction_id' => $transaction->id,
            ])->save();

            return $claim;
        });

        $points = number_format($claim->transaction->points, 0, ',', '.');
        $this->notify($claim, "Struk belanja kamu di *{$claim->outlet->name}* sudah diverifikasi. +{$points} poin masuk ke akun Ocean Space kamu.");

        return $claim;
    }

    /** $reviewer null = ditolak sistem; alasannya ditulis untuk member. */
    public function reject(ReceiptClaim $claim, ?User $reviewer, string $reason): ReceiptClaim
    {
        $claim = DB::transaction(function () use ($claim, $reviewer, $reason) {
            $claim = ReceiptClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();

            if (! $claim->isPending()) {
                $this->fail('status', 'Klaim ini sudah diperiksa.');
            }

            $claim->forceFill([
                'status' => ReceiptClaim::STATUS_REJECTED,
                'reject_reason' => $reason,
                'reviewed_by' => $reviewer?->id,
                'reviewed_at' => now(),
            ])->save();

            return $claim;
        });

        $where = $claim->outlet ? " di *{$claim->outlet->name}*" : '';
        $this->notify($claim, "Struk belanja kamu{$where} belum bisa kami proses: {$reason}");

        return $claim;
    }

    /**
     * Satu struk per outlet hanya bisa mendapat poin sekali: belum ada klaim yang disetujui dengan
     * nomor itu, dan belum pernah tercatat sebagai transaksi belanja (mis. lewat kasir).
     */
    private function ensureNotClaimed(Outlet $outlet, string $number): void
    {
        $claimed = ReceiptClaim::where('outlet_id', $outlet->id)
            ->where('receipt_number', $number)
            ->where('status', ReceiptClaim::STATUS_APPROVED)
            ->exists();

        $recorded = PointTransaction::where('outlet_id', $outlet->id)
            ->where('type', PointTransaction::TYPE_EARN)
            ->where('reference', $number)
            ->exists();

        if ($claimed || $recorded) {
            $this->fail('receipt_number', "Struk {$number} di outlet ini sudah pernah mendapat poin.");
        }
    }

    /** Pemberitahuan WhatsApp; gagal kirim tidak membatalkan keputusan admin. */
    private function notify(ReceiptClaim $claim, string $message): void
    {
        $phone = $claim->user?->phone;
        if (! $phone) {
            return;
        }

        try {
            $this->whatsApp->send($phone, $message, 'notification');
        } catch (Throwable $e) {
            Log::warning("[Receipt] notifikasi klaim #{$claim->id} gagal: {$e->getMessage()}");
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
