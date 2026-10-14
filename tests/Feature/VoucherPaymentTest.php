<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Promo;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Beli voucher dengan uang lewat Xendit Payment Session. Respons Xendit dipalsukan;
 * tidak ada request keluar sungguhan.
 */
class VoucherPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Promo $promo;

    /** Status sesi yang dikembalikan Xendit palsu untuk GET /sessions/{id}. */
    private string $gatewayStatus = 'ACTIVE';

    private int $sessionsCreated = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'loyalty.payment.xendit.secret_key' => 'xnd_development_test',
            'loyalty.payment.xendit.callback_token' => 'token-webhook-uji',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'api.xendit.co/sessions' => function () {
                $this->sessionsCreated++;

                return Http::response([
                    'payment_session_id' => 'ps-uji'.$this->sessionsCreated,
                    'payment_link_url' => 'https://dev.xen.to/uji'.$this->sessionsCreated,
                    'status' => 'ACTIVE',
                ], 201);
            },
            'api.xendit.co/sessions/*' => fn () => Http::response(['status' => $this->gatewayStatus]),
        ]);

        $brand = Brand::create(['name' => 'Padelnis', 'slug' => 'padelnis', 'category' => 'sports']);
        $this->promo = Promo::create([
            'brand_id' => $brand->id,
            'type' => Promo::TYPE_SALE,
            'title' => 'Voucher lapangan Rp100.000',
            'highlight' => 'Rp100rb',
            'voucher_value' => 100_000,
            'price_rupiah' => 75_000,
            'quota' => 10,
            'valid_days' => 30,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
        $this->member = User::factory()->create();
        Sanctum::actingAs($this->member, ['member']);
    }

    private function buy(?Promo $promo = null)
    {
        return $this->postJson('/api/v1/promos/'.($promo ?? $this->promo)->id.'/orders');
    }

    private function webhook(array $payload, string $token = 'token-webhook-uji')
    {
        return $this->postJson('/api/v1/payments/xendit/webhook', $payload, ['x-callback-token' => $token]);
    }

    public function test_buying_creates_a_xendit_payment_page_and_a_second_tap_reuses_it(): void
    {
        $order = $this->buy()
            ->assertCreated()
            ->assertJsonPath('order.status', 'pending')
            ->assertJsonPath('order.amount', 75_000)
            ->assertJsonPath('order.payment_url', 'https://dev.xen.to/uji1')
            ->json('order');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.xendit.co/sessions'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('xnd_development_test:'))
            && $request['mode'] === 'PAYMENT_LINK'
            && $request['session_type'] === 'PAY'
            && $request['amount'] === 75_000
            && $request['currency'] === 'IDR'
            && $request['reference_id'] === $order['reference']);

        $this->getJson('/api/v1/promos')
            ->assertJsonPath('data.0.voucher.state', 'pending')
            ->assertJsonPath('data.0.voucher.open_order_id', $order['id'])
            ->assertJsonPath('data.0.voucher.price_rupiah', 75_000)
            ->assertJsonPath('data.0.voucher.quota_left', 9);

        // Tombol beli ditekan lagi: tagihan yang sama, tidak membuat sesi baru di Xendit.
        $this->buy()->assertOk()->assertJsonPath('order.id', $order['id']);
        $this->assertSame(1, $this->sessionsCreated);
    }

    public function test_app_polling_issues_the_voucher_once_after_payment(): void
    {
        $orderId = $this->buy()->json('order.id');

        $this->getJson("/api/v1/me/orders/{$orderId}")->assertJsonPath('order', null)->assertJsonPath('data.status', 'pending');
        $this->assertSame(0, Voucher::count());

        $this->gatewayStatus = 'COMPLETED';
        $this->travel(5)->seconds();

        $this->getJson("/api/v1/me/orders/{$orderId}")
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_url', null)
            ->assertJsonPath('data.voucher.source', 'paid')
            ->assertJsonPath('data.voucher.value', 100_000)
            ->assertJsonPath('data.voucher.paid_amount', 75_000);

        $this->travel(5)->seconds();
        $this->getJson("/api/v1/me/orders/{$orderId}")->assertJsonPath('data.status', 'paid');

        $this->assertSame(1, $this->member->vouchers()->count());
        $this->assertSame(1, $this->promo->fresh()->issued_count);
        $this->getJson('/api/v1/me')->assertJsonPath('loyalty.active_vouchers', 1);
        $this->getJson('/api/v1/promos')->assertJsonPath('data.0.voucher.state', 'claimed');
    }

    public function test_webhook_is_verified_and_never_trusted_without_rechecking_xendit(): void
    {
        $orderId = $this->buy()->json('order.id');
        $payload = ['event' => 'payment_session.completed', 'data' => ['payment_session_id' => 'ps-uji1', 'status' => 'COMPLETED']];

        $this->webhook($payload, 'token-palsu')->assertForbidden();

        // Payload mengaku lunas, tapi Xendit bilang masih ACTIVE: voucher tidak terbit.
        $this->webhook($payload)->assertOk();
        $this->assertSame('pending', VoucherOrder::find($orderId)->status);

        $this->gatewayStatus = 'COMPLETED';
        $this->webhook($payload)->assertOk();
        $this->webhook($payload)->assertOk(); // kiriman ulang

        $this->assertSame('paid', VoucherOrder::find($orderId)->status);
        $this->assertSame(1, $this->member->vouchers()->count());
    }

    public function test_expired_payment_releases_the_quota(): void
    {
        $this->promo->update(['quota' => 1]);
        $orderId = $this->buy()->assertCreated()->json('order.id');

        // Kuota terakhir sedang dipesan orang lain.
        $other = User::factory()->create();
        Sanctum::actingAs($other, ['member']);
        $this->buy()->assertUnprocessable()->assertJsonPath('errors.voucher.0', 'Kuota voucher sudah habis.');

        $this->gatewayStatus = 'EXPIRED';
        $this->webhook(['data' => ['payment_session_id' => 'ps-uji1']])->assertOk();
        $this->assertSame('expired', VoucherOrder::find($orderId)->status);

        $this->buy()->assertCreated();
    }

    public function test_paid_member_still_gets_the_voucher_after_the_promo_ended(): void
    {
        $orderId = $this->buy()->json('order.id');
        $this->promo->update(['ends_at' => now()->subDay(), 'is_active' => false]);

        $this->gatewayStatus = 'COMPLETED';
        $this->webhook(['data' => ['payment_session_id' => 'ps-uji1']])->assertOk();

        $this->assertSame('paid', VoucherOrder::find($orderId)->status);
        $this->assertSame(Voucher::STATUS_ACTIVE, $this->member->vouchers()->first()->status());
    }

    public function test_missing_secret_key_gives_a_clear_message_and_no_open_order(): void
    {
        config(['loyalty.payment.xendit.secret_key' => '']);

        $this->buy()
            ->assertUnprocessable()
            ->assertJsonPath('errors.voucher.0', 'Pembayaran belum dikonfigurasi (XENDIT_SECRET_KEY di backend/.env masih kosong).');

        $this->assertSame(0, VoucherOrder::open()->count());
    }

    public function test_rupiah_only_voucher_cannot_be_taken_with_points_and_info_promos_cannot_be_bought(): void
    {
        $this->member->forceFill(['points_balance' => 50_000])->save();
        $this->postJson("/api/v1/promos/{$this->promo->id}/vouchers")
            ->assertUnprocessable()
            ->assertJsonPath('errors.voucher.0', 'Voucher ini dibeli lewat pembayaran, bukan poin.');

        $info = Promo::create(['title' => 'Info', 'highlight' => '20%', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        $this->buy($info)->assertUnprocessable();
        $this->assertSame(0, $this->sessionsCreated);
    }

    public function test_orders_are_private_to_their_owner(): void
    {
        $orderId = $this->buy()->json('order.id');

        Sanctum::actingAs(User::factory()->create(), ['member']);
        $this->getJson("/api/v1/me/orders/{$orderId}")->assertNotFound();
    }
}
