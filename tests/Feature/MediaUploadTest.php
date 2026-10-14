<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Outlet;
use App\Models\Promo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        $this->brand = Brand::create(['name' => 'Araichi Ramen', 'slug' => 'araichi-ramen', 'category' => 'culinary']);
    }

    private function actAsAdmin(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();
        Sanctum::actingAs($admin);
    }

    public function test_admin_uploads_promo_image_and_it_appears_in_catalog(): void
    {
        $this->actAsAdmin();
        $promo = Promo::create([
            'brand_id' => $this->brand->id, 'title' => 'Weekday lunch', 'highlight' => '20%',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);

        $path = $this->post("/api/v1/admin/promos/{$promo->id}/image", [
            'file' => UploadedFile::fake()->image('ramen.jpg', 1200, 675),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.image_url', fn ($url) => str_contains($url, '/media/promos/'))
            ->json('data.image_url');

        Storage::disk('media')->assertExists($promo->fresh()->image_path);
        $this->getJson('/api/v1/promos')->assertJsonPath('data.0.image_url', $path);
    }

    public function test_replacing_a_photo_deletes_the_old_upload_but_keeps_stock_files(): void
    {
        $this->actAsAdmin();
        $outlet = Outlet::create(['brand_id' => $this->brand->id, 'name' => 'Araichi Ciledug', 'photo_path' => 'stock/outlet-kuliner.jpg']);
        Storage::disk('media')->put('stock/outlet-kuliner.jpg', 'demo');

        $this->post("/api/v1/admin/outlets/{$outlet->id}/photo", ['file' => UploadedFile::fake()->image('a.png', 800, 600)], ['Accept' => 'application/json'])->assertOk();
        $first = $outlet->fresh()->photo_path;
        Storage::disk('media')->assertExists('stock/outlet-kuliner.jpg');

        $this->post("/api/v1/admin/outlets/{$outlet->id}/photo", ['file' => UploadedFile::fake()->image('b.png', 800, 600)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('media')->assertMissing($first);
    }

    public function test_brand_logo_is_exposed_on_outlets(): void
    {
        $this->actAsAdmin();
        Outlet::create(['brand_id' => $this->brand->id, 'name' => 'Araichi Ciledug']);

        $this->post("/api/v1/admin/brands/{$this->brand->id}/logo", ['file' => UploadedFile::fake()->image('logo.png', 512, 512)], ['Accept' => 'application/json'])
            ->assertOk();

        $this->getJson('/api/v1/outlets')->assertJsonPath('data.0.brand.logo_url', fn ($url) => str_contains($url, '/media/brands/'));
    }

    public function test_upload_rejects_non_images_and_tiny_images(): void
    {
        $this->actAsAdmin();

        $this->post("/api/v1/admin/brands/{$this->brand->id}/logo", ['file' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('file');
        $this->post("/api/v1/admin/brands/{$this->brand->id}/logo", ['file' => UploadedFile::fake()->image('kecil.png', 50, 50)], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('file');
    }

    public function test_members_and_cashiers_cannot_upload(): void
    {
        foreach ([User::ROLE_MEMBER, User::ROLE_CASHIER] as $role) {
            $user = User::factory()->create();
            $user->forceFill(['role' => $role])->save();
            Sanctum::actingAs($user);

            $this->post("/api/v1/admin/brands/{$this->brand->id}/logo", ['file' => UploadedFile::fake()->image('logo.png', 512, 512)], ['Accept' => 'application/json'])
                ->assertForbidden();
        }
    }
}
