<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_outlets_are_sorted_by_distance(): void
    {
        // Dari Ciledug, Kab. Cirebon: outlet Ciledug paling dekat.
        $outlets = $this->getJson('/api/v1/outlets?lat=-6.90&lng=108.74')->assertOk()->json('data');

        $this->assertStringContainsString('Ciledug', $outlets[0]['name']);
        $this->assertNotNull($outlets[0]['distance_km']);
        $this->assertNull(end($outlets)['distance_km']); // outlet tanpa koordinat di akhir
    }

    public function test_outlets_filter_by_category(): void
    {
        $names = collect($this->getJson('/api/v1/outlets?category=retail')->assertOk()->json('data'))->pluck('brand.category')->unique();

        $this->assertSame(['retail'], $names->values()->all());
    }

    public function test_promo_category_filter_includes_universal_promos(): void
    {
        $promos = collect($this->getJson('/api/v1/promos?category=sports')->assertOk()->json('data'));

        $this->assertTrue($promos->contains('is_universal', true));
        $this->assertTrue($promos->every(fn ($p) => $p['is_universal'] || $p['brand']['category'] === 'sports'));
    }

    public function test_open_status_uses_outlet_local_time(): void
    {
        // Sabtu 04:30 UTC = 11:30 WIB → outlet 10:00–22:00 sedang buka.
        $this->travelTo(Carbon::parse('2026-10-10 04:30:00', 'UTC'));
        $this->getJson('/api/v1/outlets/1')->assertJsonPath('data.status.open', true);

        // Sabtu 15:30 UTC = 22:30 WIB → sudah tutup.
        $this->travelTo(Carbon::parse('2026-10-10 15:30:00', 'UTC'));
        $this->getJson('/api/v1/outlets/1')->assertJsonPath('data.status.open', false);
    }

    public function test_seeded_promos_and_outlets_have_images(): void
    {
        $this->getJson('/api/v1/promos')->assertJsonPath('data.0.image_url', fn ($url) => str_contains($url, '/media/stock/promo-'));
        $this->getJson('/api/v1/outlets/1')->assertJsonPath('data.photo_url', fn ($url) => str_ends_with($url, '/media/stock/outlet-kuliner.jpg'));
        $this->assertFileExists(public_path('media/stock/outlet-kuliner.jpg'));
    }

    public function test_brands_and_tiers_are_public(): void
    {
        $this->getJson('/api/v1/brands')->assertOk()->assertJsonCount(10, 'data');
        $this->getJson('/api/v1/tiers')->assertOk()->assertJsonPath('tiers.1.key', 'gold');
    }
}
