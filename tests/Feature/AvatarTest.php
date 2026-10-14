<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    public function test_member_uploads_replaces_and_deletes_profile_photo(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->post('/api/v1/me/avatar', ['file' => UploadedFile::fake()->image('saya.jpg', 600, 600)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.avatar_url', fn ($url) => str_contains($url, '/media/avatars/'));
        $first = $user->fresh()->avatar_path;
        Storage::disk('media')->assertExists($first);

        $this->post('/api/v1/me/avatar', ['file' => UploadedFile::fake()->image('baru.png', 400, 400)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('media')->assertMissing($first);

        $this->getJson('/api/v1/me')->assertJsonPath('user.avatar_url', fn ($url) => str_contains($url, '/media/avatars/'));

        $this->deleteJson('/api/v1/me/avatar')->assertOk()->assertJsonPath('data.avatar_url', null);
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_invalid_avatar_files_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->post('/api/v1/me/avatar', ['file' => UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('file');
        $this->post('/api/v1/me/avatar', ['file' => UploadedFile::fake()->image('kecil.png', 40, 40)], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('file');
    }

    public function test_guests_cannot_upload(): void
    {
        $this->post('/api/v1/me/avatar', ['file' => UploadedFile::fake()->image('a.png', 300, 300)], ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }
}
