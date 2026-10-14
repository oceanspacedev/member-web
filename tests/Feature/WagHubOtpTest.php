<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Services\WhatsApp\DemoNumberSender;
use App\Services\WhatsApp\LogWhatsAppSender;
use App\Services\WhatsApp\WagHubWhatsAppSender;
use App\Services\WhatsApp\WhatsAppDeliveryException;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WagHubOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'loyalty.whatsapp.driver' => 'waghub',
            'loyalty.whatsapp.waghub.url' => 'https://wag.test',
            'loyalty.whatsapp.waghub.token' => 'wgh_test_token',
        ]);
    }

    public function test_otp_is_sent_through_waghub_messages_api(): void
    {
        Http::fake(['wag.test/*' => Http::response(['data' => ['id' => 'msg-1', 'status' => 'sent']], 201)]);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '081244710921'])->assertOk();

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://wag.test/api/v1/messages'
                && $request->hasHeader('Authorization', 'Bearer wgh_test_token')
                && $request->header('Idempotency-Key')[0] === $body['idempotency_key']
                && $body['recipient'] === ['type' => 'phone', 'value' => '6281244710921']
                && $body['message']['type'] === 'text'
                && preg_match('/\*\d{6}\*/', $body['message']['text']) === 1
                && $body['purpose'] === 'otp'
                && $body['mode'] === 'sync';
        });
    }

    public function test_failed_delivery_returns_error_and_allows_immediate_retry(): void
    {
        Http::fakeSequence('wag.test/*')
            ->push(['message' => 'Session disconnected'], 503)
            ->push(['data' => ['id' => 'msg-2']], 201);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '081244710921'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertSame(0, OtpCode::count());

        // Tidak terkena cooldown 60 detik karena kode sebelumnya tidak pernah terkirim.
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '081244710921'])->assertOk();
    }

    public function test_missing_token_is_reported_as_delivery_failure(): void
    {
        $this->expectException(WhatsAppDeliveryException::class);

        (new WagHubWhatsAppSender('https://wag.test', ''))->send('6281244710921', 'halo', 'otp');
    }

    public function test_demo_numbers_never_reach_the_gateway(): void
    {
        Http::fake();
        $sender = new DemoNumberSender(new WagHubWhatsAppSender('https://wag.test', 'wgh_test_token'), ['6281200000001'], new LogWhatsAppSender);

        $sender->send('6281200000001', 'kode', 'otp');

        Http::assertNothingSent();
    }

    public function test_local_environment_wraps_sender_with_demo_guard(): void
    {
        $this->app['env'] = 'local';

        $this->assertInstanceOf(DemoNumberSender::class, $this->app->make(WhatsAppSender::class));
    }
}
