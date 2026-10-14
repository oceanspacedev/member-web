<?php

namespace App\Http\Resources;

use App\Models\ReceiptClaim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReceiptClaim */
class ReceiptClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** null selama outlet belum terbaca dari struk. */
            'outlet' => $this->whenLoaded('outlet', fn () => $this->outlet ? ['id' => $this->outlet->id, 'name' => $this->outlet->name] : null),
            /** Dibaca sistem dari foto; null selama diperiksa. */
            'receipt_number' => $this->receipt_number,
            'amount' => $this->amount,
            'purchased_at' => $this->purchased_at?->toDateString(),
            /** pending | approved | rejected */
            'status' => $this->status,
            'status_label' => $this->isChecking() ? 'Sedang diperiksa' : (ReceiptClaim::STATUSES[$this->status] ?? $this->status),
            /** true = sistem masih membaca struk; aplikasi menunggu hasilnya. */
            'checking' => $this->isChecking(),
            /** true = struk perlu dicek petugas (pending setelah pemeriksaan sistem). */
            'in_review' => $this->isPending() && ! $this->isChecking(),
            'reject_reason' => $this->reject_reason,
            'points' => $this->whenLoaded('transaction', fn () => $this->transaction?->points),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
