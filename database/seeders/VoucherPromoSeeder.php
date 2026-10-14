<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Promo;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Database\Seeder;

/**
 * Contoh promo voucher (Fase 2). Aman dijalankan ulang:
 *   php artisan db:seed --class=VoucherPromoSeeder
 */
class VoucherPromoSeeder extends Seeder
{
    public function run(LoyaltyService $loyalty): void
    {
        $brand = fn (string $slug) => Brand::where('slug', $slug)->value('id');

        // [brand, judul, teks besar, keterangan, jenis, nilai, min. belanja, harga poin, harga rupiah, tier, kuota, berlaku (hari)]
        $promos = [
            ['araichi-ramen', 'Voucher Rp25.000 Araichi Ramen', 'Rp25rb', 'voucher gratis', Promo::TYPE_CLAIM, 25_000, 100_000, null, null, null, 200, 14],
            // Sesuai contoh dokumen: voucher Rp100.000 dijual Rp75.000 — bayar online, atau 7.500 poin (× Rp10).
            ['padelnis', 'Voucher sewa lapangan Rp100.000', 'Rp100rb', 'cuma Rp75rb', Promo::TYPE_SALE, 100_000, 0, 7_500, 75_000, null, 100, 30],
            [null, 'Voucher Rp150.000 khusus Platinum', 'Rp150rb', 'eksklusif', Promo::TYPE_CLAIM, 150_000, 500_000, null, null, 'platinum', 50, 30],
            [null, 'Voucher ulang tahun Rp50.000', 'Rp50rb', 'ulang tahun', Promo::TYPE_BIRTHDAY, 50_000, 150_000, null, null, 'gold', null, null],
        ];

        foreach ($promos as [$slug, $title, $highlight, $caption, $type, $value, $minSpend, $price, $priceRupiah, $tier, $quota, $days]) {
            Promo::updateOrCreate(['title' => $title], [
                'brand_id' => $slug ? $brand($slug) : null,
                'type' => $type,
                'highlight' => $highlight,
                'highlight_caption' => $caption,
                'description' => 'Contoh promo voucher — ganti dengan materi promo resmi.',
                'terms' => $minSpend ? 'Minimal belanja Rp'.number_format($minSpend, 0, ',', '.').'. Satu voucher per struk.' : 'Satu voucher per struk.',
                'voucher_value' => $value,
                'min_spend' => $minSpend,
                'price_points' => $price,
                'price_rupiah' => $priceRupiah,
                'min_tier' => $tier,
                'quota' => $quota,
                'valid_days' => $days,
                'starts_at' => now()->startOfMonth(),
                'ends_at' => $type === Promo::TYPE_BIRTHDAY ? now()->addYear()->endOfYear() : now()->addMonths(2)->endOfMonth(),
            ]);
        }

        // Saldo awal akun demo agar fitur "Tukar poin" bisa dicoba tanpa belanja dulu.
        $demo = User::where('phone', '6281200000001')->first();
        $admin = User::where('role', User::ROLE_ADMIN)->first();
        if ($demo && $admin && ! $demo->pointTransactions()->exists()) {
            $loyalty->adjust($demo, 10_000, 'Saldo awal akun demo', $admin);
        }
    }
}
