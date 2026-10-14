<?php

namespace App\Services\Receipts;

/**
 * Hasil baca satu foto struk. Semua angka dan teks berasal dari foto; keputusan diambil
 * ReceiptVerifier dengan aturan yang bisa diaudit, bukan oleh pembaca.
 */
final class ReceiptReading
{
    public const CAPTURE_PAPER = 'photo_of_paper';

    public const CAPTURE_SCREEN = 'photo_of_screen';

    public const CAPTURE_SCREENSHOT = 'screenshot';

    public const CAPTURE_DIGITAL = 'digital_receipt';

    public const CAPTURE_OTHER = 'other';

    /**
     * @param  list<string>  $tamperSigns  tanda editan yang terlihat (angka ditimpa, font beda, dll.)
     */
    public function __construct(
        public readonly bool $isReceipt,
        public readonly bool $legible,
        public readonly ?int $outletId,
        public readonly ?string $merchantName,
        public readonly ?string $receiptNumber,
        public readonly ?string $purchasedAt, // Y-m-d atau Y-m-d H:i, waktu setempat
        public readonly ?int $total,
        public readonly string $capture,
        public readonly bool $tamperSuspected,
        public readonly array $tamperSigns,
        public readonly float $confidence,
        public readonly ?string $notes = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $string = fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        $total = $data['total'] ?? null;

        return new self(
            isReceipt: (bool) ($data['is_receipt'] ?? false),
            legible: (bool) ($data['legible'] ?? false),
            outletId: isset($data['outlet_id']) && is_numeric($data['outlet_id']) ? (int) $data['outlet_id'] : null,
            merchantName: $string('merchant_name'),
            receiptNumber: $string('receipt_number'),
            purchasedAt: $string('purchased_at'),
            total: is_numeric($total) ? (int) round((float) $total) : null,
            capture: in_array($data['capture'] ?? null, self::captures(), true) ? $data['capture'] : self::CAPTURE_OTHER,
            tamperSuspected: (bool) ($data['tamper_suspected'] ?? false),
            tamperSigns: array_values(array_filter(array_map('strval', (array) ($data['tamper_signs'] ?? [])))),
            confidence: max(0.0, min(1.0, (float) ($data['confidence'] ?? 0))),
            notes: $string('notes'),
        );
    }

    /** @return list<string> */
    public static function captures(): array
    {
        return [self::CAPTURE_PAPER, self::CAPTURE_SCREEN, self::CAPTURE_SCREENSHOT, self::CAPTURE_DIGITAL, self::CAPTURE_OTHER];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'is_receipt' => $this->isReceipt,
            'legible' => $this->legible,
            'outlet_id' => $this->outletId,
            'merchant_name' => $this->merchantName,
            'receipt_number' => $this->receiptNumber,
            'purchased_at' => $this->purchasedAt,
            'total' => $this->total,
            'capture' => $this->capture,
            'tamper_suspected' => $this->tamperSuspected,
            'tamper_signs' => $this->tamperSigns,
            'confidence' => $this->confidence,
            'notes' => $this->notes,
        ];
    }
}
