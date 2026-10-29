<?php

namespace App\Services\Receipts;

use App\Models\Outlet;
use Illuminate\Support\Collection;

/**
 * Instruksi dan bentuk jawaban untuk pembaca struk otomatis. Model hanya membaca dan menilai;
 * keputusan memberi poin tetap di ReceiptVerifier.
 */
final class ReceiptPrompt
{
    public const SYSTEM = <<<'TXT'
Kamu memeriksa foto struk belanja yang dikirim member program loyalti Space Club untuk mendapat poin.
Outlet Space Club memakai mesin kasir pihak ketiga, jadi foto struk adalah satu-satunya bukti belanja.
Tugasmu membaca struk dengan teliti dan menilai keasliannya. Jangan menebak: bila sebuah nilai tidak
terbaca jelas, isi null dan turunkan confidence.

Cara mengisi:
- is_receipt: true hanya bila foto berisi struk/nota belanja.
- legible: true bila nomor struk, tanggal, dan total terbaca jelas.
- outlet_id: id dari daftar outlet di bawah yang cocok dengan nama usaha, cabang, atau alamat di struk.
  null bila tidak ada yang cocok dengan yakin. Jangan memilih outlet hanya karena brandnya mirip bila
  cabangnya jelas berbeda. Ikuti "Ciri struk per brand" di bawah daftar outlet.
- merchant_match: member_outlet bila struk jelas dari salah satu outlet di daftar; other_merchant bila
  struk jelas dari usaha lain yang tidak ada di daftar (mis. minimarket seperti Alfamart/Indomaret,
  supermarket, SPBU, restoran atau toko lain) — poin hanya untuk belanja di outlet Space Club, jadi
  struk seperti ini harus ditandai other_merchant dengan outlet_id null; unclear bila nama usaha tidak
  terbaca atau tidak bisa dipastikan.
- transaction_type: purchase bila pelanggan membayar belanja di outlet; buyback bila outlet yang membeli
  barang dari pelanggan (mis. "Faktur Beli" toko emas); other bila bukan bukti pembayaran (tagihan
  yang belum dibayar, slip pesanan, catatan internal tanpa pembayaran).
- merchant_name: nama usaha dan cabang persis seperti tertulis di struk.
- receipt_number: nomor struk/invoice/transaksi persis seperti tertulis (bukan nomor meja/antrean/kasir).
- purchased_at: tanggal dan jam transaksi, format "YYYY-MM-DD HH:MM" (atau "YYYY-MM-DD" bila jam tidak ada).
  Struk Indonesia biasanya memakai urutan hari/bulan/tahun.
- total: jumlah akhir yang dibayar dalam rupiah, bilangan bulat tanpa pemisah ribuan
  (mis. "Rp 125.500" menjadi 125500). Pakai grand total setelah diskon/pajak, bukan subtotal atau kembalian.
- capture: photo_of_paper (foto kertas struk), photo_of_screen (foto layar HP/komputer),
  screenshot (tangkapan layar), digital_receipt (struk elektronik/email), other.
- tamper_suspected dan tamper_signs: periksa tanda foto diedit atau dimanipulasi, misalnya angka atau
  tanggal dengan font, ketebalan, warna, atau kerapatan yang berbeda dari teks di sekitarnya; tepi
  tempelan atau latar yang tidak menyatu; teks yang tidak sejajar dengan garis cetak; total yang tidak
  sama dengan penjumlahan item; struk yang tampak dicetak ulang/dibuat sendiri; satu struk yang ditutup
  sebagian. Tuliskan setiap tanda singkat dalam bahasa Indonesia. Bila tidak ada, kosongkan.
  Tanda salinan dari mesin kasir ("Cetak Ulang", "Copy : 2", "Print Cnt", watermark "COPY") dan warna
  kertas nota yang berbeda-beda bukan tanda editan.
- Tanggal hari ini diberikan di pesan. Jangan menganggap tanggal mencurigakan hanya karena tahunnya
  terasa baru; aturan umur struk diperiksa sistem, bukan olehmu.
- confidence: 0 sampai 1, seberapa yakin semua nilai di atas benar dan struk asli.
- notes: catatan singkat untuk petugas bila ada yang perlu diperhatikan, atau null.
TXT;

    /** @param  Collection<int, Outlet>  $outlets */
    public static function outletList(Collection $outlets): string
    {
        $lines = $outlets->map(fn (Outlet $o) => sprintf(
            '- id %d: %s (brand %s)%s',
            $o->id,
            $o->name,
            $o->brand?->name ?? '-',
            filled($o->address) ? ', '.$o->address : (filled($o->city) ? ', '.$o->city : ''),
        ));

        $text = "Daftar outlet Space Club:\n".$lines->implode("\n");

        // Ciri struk asli tiap brand (diisi admin), agar struk outlet dikenali dan struk toko lain ditolak.
        $guides = $outlets->map(fn (Outlet $o) => $o->brand)->filter(fn ($brand) => filled($brand?->receipt_guide))->unique('id')
            ->map(fn ($brand) => '### '.$brand->name."\n".trim($brand->receipt_guide));
        if ($guides->isNotEmpty()) {
            $text .= "\n\nCiri struk per brand:\n".$guides->implode("\n\n");
        }

        return $text;
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $nullable = fn (string $type) => ['type' => [$type, 'null']];

        return [
            'type' => 'object',
            'properties' => [
                'is_receipt' => ['type' => 'boolean'],
                'legible' => ['type' => 'boolean'],
                'outlet_id' => $nullable('integer'),
                'merchant_match' => ['type' => 'string', 'enum' => ReceiptReading::merchantMatches()],
                'transaction_type' => ['type' => 'string', 'enum' => ReceiptReading::transactionTypes()],
                'merchant_name' => $nullable('string'),
                'receipt_number' => $nullable('string'),
                'purchased_at' => $nullable('string'),
                'total' => $nullable('integer'),
                'capture' => ['type' => 'string', 'enum' => ReceiptReading::captures()],
                'tamper_suspected' => ['type' => 'boolean'],
                'tamper_signs' => ['type' => 'array', 'items' => ['type' => 'string']],
                'confidence' => ['type' => 'number'],
                'notes' => $nullable('string'),
            ],
            'required' => ['is_receipt', 'legible', 'outlet_id', 'merchant_match', 'transaction_type', 'merchant_name', 'receipt_number', 'purchased_at', 'total', 'capture', 'tamper_suspected', 'tamper_signs', 'confidence', 'notes'],
            'additionalProperties' => false,
        ];
    }

    /** Pesan per foto: tanggal hari ini agar model tidak salah menilai tahun sebagai "masa depan". */
    public static function userText(): string
    {
        return 'Hari ini: '.now(config('loyalty.timezone'))->format('Y-m-d').' WIB. Baca dan nilai struk pada foto ini.';
    }
}
