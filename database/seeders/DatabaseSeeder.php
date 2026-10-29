<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Outlet;
use App\Models\Promo;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * Data awal dari dokumen rancangan. Alamat, koordinat, dan kontak yang
     * belum diketahui dibiarkan null dan perlu dilengkapi tim operasional.
     */
    public function run(): void
    {
        $hours = ['mon' => '10:00-22:00', 'tue' => '10:00-22:00', 'wed' => '10:00-22:00', 'thu' => '10:00-22:00', 'fri' => '10:00-22:00', 'sat' => '10:00-22:00', 'sun' => '10:00-22:00'];

        // [brand, kategori, entitas, warna, [[outlet, kota, lat, lng], ...]]
        // Koordinat yang diisi hanyalah perkiraan pusat kota/kecamatan — ganti dengan titik outlet sebenarnya.
        $data = [
            ['Ulon Signature', 'culinary', 'CV. Arabumi Panca Abadi', '#3F5A2A', [['Ulon Signature', null, null, null]]],
            ['Araichi Ramen', 'culinary', 'CV. Arabumi Panca Abadi', '#9A3B1E', [['Araichi Ramen', null, null, null]]],
            ['Ulon', 'culinary', 'PT. Penta Boga Perkasa / CV. Ciledug Boga Perkasa', '#3F5A2A', [
                ['Ulon Pekanbaru', 'Pekanbaru', 0.5070677, 101.4477793],
                ['Ulon Ciledug', 'Cirebon', -6.9010, 108.7400],
            ]],
            ['J Chicken', 'fastfood', 'PT. Senaraya Indonesia Prima / PT. Kreasi Lima Cahaya', '#C2410C', [
                ['J Chicken Tuparev', null, null, null],
                ['J Chicken Ciledug', 'Cirebon', -6.9020, 108.7415],
            ]],
            ['Luuca', 'fastfood', 'PT. Kreasi Lima Cahaya', '#7A3E9D', [['Luuca', null, null, null]]],
            ['Momoyo', 'fastfood', 'Complete Kulinari', '#B4235A', [['Momoyo', null, null, null]]],
            ['Toko Mas An An', 'retail', 'CV. Bersama CS', '#8A6A1F', [['Toko Mas An An', null, null, null]]],
            ['Complete Mulia', 'retail', 'CV. Inspirasi Mulia Sejahtera / CV. Complete Mulia Purwakarta', '#5B3E8A', [
                ['Complete Mulia Arjawinangun', 'Cirebon', -6.6447, 108.4091],
                ['Complete Mulia Purwakarta', 'Purwakarta', -6.5569, 107.4431],
            ]],
            ['Padelnis', 'sports', 'PT. Senaraya Indonesia Prima', '#1F4E8C', [['Padelnis', null, null, null]]],
            ['Grandmonde', 'sports', 'PT. Senaraya Indonesia Prima', '#2C3E66', [['Grandmonde', null, null, null]]],
        ];

        // Foto stok Unsplash sementara (public/media/stock, kredit di CREDITS.md) — ganti dengan foto asli
        // lewat POST /api/v1/admin/... setelah brand mengirim fotonya.
        $outletPhotos = [
            'Ulon Signature' => 'stock/outlet-kuliner.jpg',
            'Ulon' => 'stock/outlet-kuliner.jpg',
            'Araichi Ramen' => 'stock/outlet-ramen.jpg',
            'J Chicken' => 'stock/outlet-fastfood.jpg',
            'Luuca' => 'stock/outlet-kiosk.jpg',
            'Momoyo' => 'stock/outlet-kiosk.jpg',
            'Toko Mas An An' => 'stock/outlet-perhiasan.jpg',
            'Complete Mulia' => 'stock/outlet-retail.jpg',
            'Padelnis' => 'stock/outlet-sports.jpg',
            'Grandmonde' => 'stock/outlet-sports.jpg',
        ];

        $brands = [];
        foreach ($data as [$name, $category, $entity, $color, $outlets]) {
            $brand = Brand::updateOrCreate(['slug' => str($name)->slug()], [
                'name' => $name, 'category' => $category, 'legal_entity' => $entity, 'color' => $color,
            ]);
            $brands[$name] = $brand;

            foreach ($outlets as [$outletName, $city, $lat, $lng]) {
                Outlet::updateOrCreate(['brand_id' => $brand->id, 'name' => $outletName], [
                    'city' => $city, 'latitude' => $lat, 'longitude' => $lng, 'opening_hours' => $hours,
                    'photo_path' => $outletPhotos[$name],
                ]);
            }
        }

        $promos = [
            [null, 'Gratis Es Teh Manis untuk member baru', 'Gratis', 'Es Teh Manis', 'stock/promo-es-teh.jpg'],
            ['Araichi Ramen', 'Weekday lunch, Senin–Jumat 11.00–15.00', '20%', 'diskon', 'stock/promo-ramen.jpg'],
            ['Ulon Signature', 'Makan keluarga akhir pekan, minimal Rp300.000', 'Poin 3x', 'akhir pekan', 'stock/promo-keluarga.jpg'],
            ['J Chicken', 'Beli 1 gratis 1 paket ayam setiap Rabu', 'B1G1', 'paket ayam', 'stock/promo-ayam.jpg'],
            ['Toko Mas An An', 'Diskon ongkos pembuatan perhiasan untuk member Gold ke atas', '50%', 'ongkos', 'stock/promo-perhiasan.jpg'],
            ['Padelnis', 'Sewa lapangan pagi 06.00–10.00', 'Poin 2x', 'pagi hari', 'stock/promo-padel.jpg'],
        ];

        foreach ($promos as [$brand, $title, $highlight, $caption, $image]) {
            Promo::updateOrCreate(['title' => $title], [
                'brand_id' => $brand ? $brands[$brand]->id : null,
                'min_tier' => str_contains($title, 'Gold ke atas') ? 'gold' : null,
                'image_path' => $image,
                'highlight' => $highlight,
                'highlight_caption' => $caption,
                'description' => 'Contoh promo — ganti dengan materi promo resmi.',
                'starts_at' => now()->startOfMonth(),
                'ends_at' => now()->addMonths(2)->endOfMonth(),
            ]);
        }

        // Akun demo untuk development (login OTP; kode muncul di storage/logs/laravel.log).
        User::firstOrCreate(['phone' => '6281200000001'], ['name' => 'Member Demo', 'birth_date' => '1995-11-14', 'phone_verified_at' => now()]);

        // Profil lengkap (nama + tanggal lahir) agar akun demo langsung masuk ke aplikasi tanpa layar lengkapi profil.
        $cashier = User::firstOrCreate(['phone' => '6281200000002'], ['name' => 'Kasir Ulon Ciledug', 'birth_date' => '1992-05-20', 'phone_verified_at' => now()]);
        $cashier->forceFill([
            'role' => User::ROLE_CASHIER,
            'outlet_id' => Outlet::where('name', 'Ulon Ciledug')->value('id'),
        ])->save();

        // Admin: masuk lewat WhatsApp OTP (nomor di atas) atau email + password dari .env (ADMIN_EMAIL / ADMIN_PASSWORD).
        $admin = User::firstOrCreate(['phone' => '6281200000003'], ['name' => 'Admin Space Club', 'phone_verified_at' => now()]);
        $admin->forceFill(['role' => User::ROLE_ADMIN]);
        if (filled(config('loyalty.admin.email')) && filled(config('loyalty.admin.password'))) {
            $admin->forceFill([
                'email' => config('loyalty.admin.email'),
                'password' => config('loyalty.admin.password'),
                'email_verified_at' => $admin->email_verified_at ?? now(),
            ]);
        }
        $admin->save();
        $admin->grantSuperAdmin();

        $this->call(VoucherPromoSeeder::class);

        // Daftar izin untuk menu Peran & izin (Shield): satu per aksi resource, widget dasbor, dan izin khusus.
        Artisan::call('shield:generate', ['--all' => true, '--panel' => 'admin', '--option' => 'permissions', '--no-interaction' => true]);
    }
}
