<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{phone: string, message: string, purpose: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(WhatsAppSender::class, new class($this->sent) implements WhatsAppSender
        {
            public function __construct(private array &$sent) {}

            public function send(string $phone, string $message, string $purpose = 'notification'): void
            {
                $this->sent[] = compact('phone', 'message', 'purpose');
            }
        });
    }

    private function lastCode(): string
    {
        preg_match('/\*(\d{6})\*/', end($this->sent)['message'], $m);

        return $m[1];
    }

    public function test_member_registers_with_whatsapp_otp(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0812-4471-0921'])
            ->assertOk()
            ->assertJsonPath('phone', '6281244710921');

        $this->assertSame('6281244710921', $this->sent[0]['phone']);
        $this->assertSame('otp', $this->sent[0]['purpose']);

        $response = $this->postJson('/api/v1/auth/otp/verify', ['phone' => '+62 812 4471 0921', 'code' => $this->lastCode()])
            ->assertOk()
            ->assertJsonPath('is_new', true)
            ->assertJsonPath('user.tier', 'silver')
            ->assertJsonPath('user.profile_complete', false);

        $this->withToken($response->json('token'))
            ->patchJson('/api/v1/me', ['name' => 'Andini', 'birth_date' => '1995-11-14', 'email' => 'andini@example.com'])
            ->assertOk()
            ->assertJsonPath('data.profile_complete', true);

        $this->assertStringStartsWith('LC', User::first()->member_code);
    }

    public function test_wrong_code_is_rejected_and_attempts_are_limited(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '081244710921'])->assertOk();
        $code = $this->lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/otp/verify', ['phone' => '081244710921', 'code' => $wrong])->assertUnprocessable();
        }

        // Setelah 5x salah, kode benar pun tidak berlaku lagi.
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '081244710921', 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_otp_resend_has_cooldown(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '081244710921'])->assertOk();
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '081244710921'])->assertJsonValidationErrors('phone');

        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '081244710921'])->assertOk();
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '12345'])->assertJsonValidationErrors('phone');
    }

    public function test_birth_date_can_only_be_set_once(): void
    {
        $user = User::factory()->create(['birth_date' => '1990-01-01']);

        $this->actingAs($user)->patchJson('/api/v1/me', ['birth_date' => '1990-06-01'])
            ->assertJsonValidationErrors('birth_date');
    }

    public function test_google_login_links_account_by_verified_email(): void
    {
        config(['loyalty.google_client_ids' => ['app-client-id']]);
        $existing = User::factory()->create(['email' => 'andini@example.com']);

        Http::fake(['oauth2.googleapis.com/*' => Http::response([
            'aud' => 'app-client-id', 'iss' => 'https://accounts.google.com', 'exp' => time() + 600,
            'sub' => 'google-123', 'email' => 'andini@example.com', 'email_verified' => 'true', 'name' => 'Andini',
        ])]);

        $this->postJson('/api/v1/auth/google', ['id_token' => 'token'])
            ->assertOk()
            ->assertJsonPath('is_new', false)
            ->assertJsonPath('user.id', $existing->id);

        $this->assertSame('google-123', $existing->fresh()->google_id);
    }

    public function test_google_token_for_other_app_is_rejected(): void
    {
        config(['loyalty.google_client_ids' => ['app-client-id']]);
        Http::fake(['oauth2.googleapis.com/*' => Http::response([
            'aud' => 'someone-else', 'iss' => 'accounts.google.com', 'exp' => time() + 600, 'sub' => 'x',
        ])]);

        $this->postJson('/api/v1/auth/google', ['id_token' => 'token'])->assertJsonValidationErrors('id_token');
    }
}
