<?php

namespace App\Jobs;

use App\Models\ReceiptClaim;
use App\Services\ReceiptService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Pemeriksaan otomatis satu foto struk, dijalankan setelah member mengirimnya. */
class VerifyReceiptClaim implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 240;

    public function __construct(public ReceiptClaim $claim) {}

    public function handle(ReceiptService $receipts): void
    {
        $receipts->verify($this->claim);
    }

    /** Gagal total: jangan biarkan struk menggantung, serahkan ke admin. */
    public function failed(?Throwable $e): void
    {
        $claim = $this->claim->fresh();

        if ($claim?->isChecking()) {
            $claim->forceFill([
                'decision' => ReceiptClaim::DECISION_NEEDS_REVIEW,
                'flags' => ['Pemeriksaan otomatis gagal; periksa manual.'],
                'verified_at' => now(),
            ])->save();
        }
    }

    /** Lewat antrean bila worker/Horizon berjalan; jika tidak, langsung setelah respons terkirim. */
    public static function for(ReceiptClaim $claim): void
    {
        config('loyalty.receipts.queue')
            ? static::dispatch($claim)
            : static::dispatchAfterResponse($claim);
    }
}
