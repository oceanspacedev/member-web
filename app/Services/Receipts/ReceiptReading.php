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

    /** Struk dari salah satu outlet Space Club di daftar. */
    public const MERCHANT_MEMBER = 'member_outlet';

    /** Jelas dari usaha lain (minimarket, restoran lain, dll.): tidak bisa ditukar poin. */
    public const MERCHANT_OTHER = 'other_merchant';

    public const MERCHANT_UNCLEAR = 'unclear';

    /** Member membeli dari outlet: satu-satunya transaksi yang mendapat poin. */
    public const TRANSACTION_PURCHASE = 'purchase';

    /** Outlet membeli barang dari pelanggan (mis. faktur beli toko emas). */
    public const TRANSACTION_BUYBACK = 'buyback';

    /** Bukan bukti pembayaran belanja (tagihan belum dibayar, slip pesanan, dll.). */
    public const TRANSACTION_OTHER = 'other';

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
        public readonly string $merchantMatch = self::MERCHANT_UNCLEAR,
        public readonly string $transactionType = self::TRANSACTION_PURCHASE,
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
            purchasedAt: self::normalizeDate($string('purchased_at')),
            total: is_numeric($total) ? (int) round((float) $total) : null,
            capture: in_array($data['capture'] ?? null, self::captures(), true) ? $data['capture'] : self::CAPTURE_OTHER,
            tamperSuspected: (bool) ($data['tamper_suspected'] ?? false),
            tamperSigns: array_values(array_filter(array_map('strval', (array) ($data['tamper_signs'] ?? [])))),
            confidence: max(0.0, min(1.0, (float) ($data['confidence'] ?? 0))),
            notes: $string('notes'),
            merchantMatch: in_array($data['merchant_match'] ?? null, self::merchantMatches(), true) ? $data['merchant_match'] : self::MERCHANT_UNCLEAR,
            transactionType: in_array($data['transaction_type'] ?? null, self::transactionTypes(), true) ? $data['transaction_type'] : self::TRANSACTION_PURCHASE,
        );
    }

    /**
     * Model kadang menulis detik atau pemisah "T" (2026-10-28 13:29:50, 2026-10-28T13:29):
     * samakan ke "Y-m-d H:i" agar struk yang terbaca jelas tidak dianggap tak terbaca.
     */
    private static function normalizeDate(?string $value): ?string
    {
        if ($value && preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2})(?::\d{2}(?:\.\d+)?)?)?$/', $value, $m)) {
            return isset($m[2]) ? "{$m[1]} {$m[2]}" : $m[1];
        }

        return $value;
    }

    /** @return list<string> */
    public static function merchantMatches(): array
    {
        return [self::MERCHANT_MEMBER, self::MERCHANT_OTHER, self::MERCHANT_UNCLEAR];
    }

    /** @return list<string> */
    public static function transactionTypes(): array
    {
        return [self::TRANSACTION_PURCHASE, self::TRANSACTION_BUYBACK, self::TRANSACTION_OTHER];
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
            'merchant_match' => $this->merchantMatch,
            'transaction_type' => $this->transactionType,
        ];
    }
}
