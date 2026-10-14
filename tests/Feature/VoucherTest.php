<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Outlet;
use App\Models\PointTransaction;
use App\Models\Promo;
use App\Models\User;
use App\Models\Voucher;
use App\Services\VoucherQr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 2: klaim gratis, tukar poin, voucher ulang tahun, promo per tier, dan pemakaian voucher di kasir.
 */
class VoucherTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $araichi;

    private Outlet $padelnis;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->araichi = Outlet::create(['brand_id' => Brand::create(['name' => 'Araichi Ramen', 'slug' => 'araichi-ramen', 'category' => 'culinary'])->id, 'name' => 'Araichi Ramen']);
        $this->padelnis = Outlet::create(['brand_id' => Brand::create(['name' => 'Padelnis', 'slug' => 'padelnis', 'category' => 'sports'])->id, 'name' => 'Padelnis']);
        $this->member = User::factory()->create(['name' => 'Rina']);
    }

    private function promo(array $attributes = []): Promo
    {
        return Promo::create($attributes + [
            'brand_id' => $this->araichi->brand_id,
            'type' => Promo::TYPE_CLAIM,
            'title' => 'Voucher Rp25.000 Araichi',
            'highlight' => 'Rp25rb',
            'voucher_value' => 25_000,
            'min_spend' => 100_000,
            'quota' => 100,
            'valid_days' => 14,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    private function claim(Promo $promo, ?User $member = null)
    {
        Sanctum::actingAs($member ?? $this->member, ['member']);

        return $this->postJson("/api/v1/promos/{$promo->id}/vouchers");
    }

    private function voucherQr(Voucher $voucher): string
    {
        return app(VoucherQr::class)->issue($voucher)['payload'];
    }

    private function actAsCashier(Outlet $outlet): User
    {
        $cashier = User::factory()->cashier($outlet->id)->create();
        Sanctum::actingAs($cashier, ['member', 'pos']);

        return $cashier;
    }

    public function test_member_claims_a_free_voucher_and_sees_it_in_my_vouchers(): void
    {
        $promo = $this->promo();

        $this->claim($promo)
            ->assertCreated()
            ->assertJsonPath('voucher.status', 'active')
            ->assertJsonPath('voucher.value', 25_000)
            ->assertJsonPath('loyalty.active_vouchers', 1);

        $this->getJson('/api/v1/me/vouchers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Voucher Rp25.000 Araichi');
        $this->getJson('/api/v1/promos')->assertJsonPath('data.0.voucher.state', 'claimed')->assertJsonPath('data.0.voucher.quota_left', 99);

        // Batas 1 per member.
        $this->claim($promo)->assertUnprocessable()->assertJsonPath('errors.voucher.0', 'Kamu sudah mengambil voucher ini.');
        $this->assertSame(1, $promo->fresh()->issued_count);
    }

    public function test_quota_runs_out(): void
    {
        $promo = $this->promo(['quota' => 1]);

        $this->claim($promo)->assertCreated();
        $this->claim($promo, User::factory()->create())->assertUnprocessable()->assertJsonPath('errors.voucher.0', 'Kuota voucher sudah habis.');
    }

    public function test_tier_exclusive_promo_is_visible_but_locked_for_lower_tiers(): void
    {
        $promo = $this->promo(['brand_id' => null, 'title' => 'Khusus Platinum', 'min_tier' => 'platinum']);

        Sanctum::actingAs($this->member, ['member']);
        $this->getJson('/api/v1/promos')
            ->assertJsonPath('data.0.min_tier_label', 'Platinum')
            ->assertJsonPath('data.0.voucher.state', 'locked');
        $this->claim($promo)->assertUnprocessable()->assertJsonPath('errors.voucher.0', 'Voucher ini khusus member Platinum ke atas.');

        $platinum = User::factory()->create(['tier' => 'platinum']);
        $this->claim($promo, $platinum)->assertCreated();
    }

    public function test_voucher_bought_with_points_deducts_the_balance(): void
    {
        $promo = $this->promo(['brand_id' => $this->padelnis->brand_id, 'type' => Promo::TYPE_SALE, 'title' => 'Voucher lapangan', 'voucher_value' => 100_000, 'min_spend' => 0, 'price_points' => 7_500]);
        $this->member->forceFill(['points_balance' => 8_000])->save();

        $this->claim($promo)
            ->assertCreated()
            ->assertJsonPath('voucher.source', 'purchase')
            ->assertJsonPath('voucher.points_spent', 7_500)
            ->assertJsonPath('loyalty.points_balance', 500);

        $this->assertDatabaseHas('point_transactions', ['user_id' => $this->member->id, 'type' => PointTransaction::TYPE_VOUCHER, 'points' => -7_500, 'amount' => 75_000]);
    }

    public function test_not_enough_points_issues_nothing(): void
    {
        $promo = $this->promo(['type' => Promo::TYPE_SALE, 'price_points' => 7_500]);
        $this->member->forceFill(['points_balance' => 100])->save();

        $this->claim($promo)->assertUnprocessable()->assertJsonValidationErrors('points');

        $this->assertSame(0, Voucher::count());
        $this->assertSame(0, $promo->fresh()->issued_count);
        $this->assertSame(100, $this->member->fresh()->points_balance);
    }

    public function test_info_promos_cannot_be_claimed_and_birthday_promos_stay_out_of_the_catalog(): void
    {
        $info = $this->promo(['type' => Promo::TYPE_INFO, 'title' => 'Diskon 20%']);
        $this->promo(['type' => Promo::TYPE_BIRTHDAY, 'title' => 'Ulang tahun']);

        $this->claim($info)->assertUnprocessable();
        $this->getJson('/api/v1/promos')->assertJsonCount(1, 'data')->assertJsonPath('data.0.voucher', null);
    }

    public function test_birthday_voucher_is_issued_once_in_the_birthday_month_for_gold_and_above(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00', 'Asia/Jakarta'));
        $this->promo(['brand_id' => null, 'type' => Promo::TYPE_BIRTHDAY, 'title' => 'Voucher ulang tahun', 'min_tier' => 'gold', 'quota' => null, 'valid_days' => null, 'voucher_value' => 50_000, 'min_spend' => 150_000]);

        $gold = User::factory()->create(['tier' => 'gold', 'birth_date' => '1995-11-14']);
        $silver = User::factory()->create(['tier' => 'silver', 'birth_date' => '1995-11-20']);
        $otherMonth = User::factory()->create(['tier' => 'gold', 'birth_date' => '1995-03-01']);

        foreach ([$gold, $silver, $otherMonth] as $user) {
            Sanctum::actingAs($user, ['member']);
            $this->getJson('/api/v1/me')->assertOk();
            $this->getJson('/api/v1/me')->assertOk();
        }

        $this->assertSame(1, $gold->vouchers()->count());
        $this->assertSame(0, $silver->vouchers()->count());
        $this->assertSame(0, $otherMonth->vouchers()->count());

        $voucher = $gold->vouchers()->first();
        $this->assertSame(Voucher::SOURCE_BIRTHDAY, $voucher->source);
        $this->assertSame('2026-11-30 23:59:59', $voucher->expires_at->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'));

        // Jadwal harian tidak menerbitkan dobel.
        $this->artisan('vouchers:birthday')->assertSuccessful();
        $this->assertSame(1, $gold->vouchers()->count());

        Carbon::setTestNow();
    }

    public function test_cashier_redeems_voucher_and_points_come_from_the_amount_paid(): void
    {
        $this->claim($this->promo())->assertCreated();
        $voucher = $this->member->vouchers()->first();
        $this->actAsCashier($this->araichi);

        $this->postJson('/api/v1/pos/vouchers/check', ['qr' => $this->voucherQr($voucher)])
            ->assertOk()
            ->assertJsonPath('usable', true)
            ->assertJsonPath('member.name', 'Rina');

        // Belanja Rp150.000 − voucher Rp25.000 = bayar Rp125.000 → Silver 2% = 250 poin.
        $payload = ['qr' => $this->voucherQr($voucher), 'amount' => 150_000, 'reference' => 'STRUK-77'];
        $this->postJson('/api/v1/pos/vouchers/redeem', $payload)
            ->assertOk()
            ->assertJsonPath('discount', 25_000)
            ->assertJsonPath('pay_amount', 125_000)
            ->assertJsonPath('transaction.points', 250)
            ->assertJsonPath('loyalty.points_balance', 250)
            ->assertJsonPath('voucher.status', 'used');

        // Request ulang (mis. sinyal putus) tidak memotong/menambah poin dua kali.
        $this->postJson('/api/v1/pos/vouchers/redeem', $payload)->assertOk()->assertJsonPath('pay_amount', 125_000);
        $this->assertSame(250, $this->member->fresh()->points_balance);
        $this->assertSame(1, $this->member->pointTransactions()->count());

        // Struk lain: voucher sudah terpakai.
        $this->postJson('/api/v1/pos/vouchers/redeem', [...$payload, 'reference' => 'STRUK-78'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('voucher');
    }

    public function test_voucher_is_refused_at_another_brand_below_min_spend_or_when_expired(): void
    {
        $this->claim($this->promo())->assertCreated();
        $voucher = $this->member->vouchers()->first();

        $this->actAsCashier($this->padelnis);
        $this->postJson('/api/v1/pos/vouchers/check', ['qr' => $this->voucherQr($voucher)])
            ->assertOk()
            ->assertJsonPath('usable', false)
            ->assertJsonPath('problem', 'Voucher ini hanya berlaku di Araichi Ramen.');
        $this->postJson('/api/v1/pos/vouchers/redeem', ['qr' => $this->voucherQr($voucher), 'amount' => 200_000, 'reference' => 'P-1'])->assertUnprocessable();

        $this->actAsCashier($this->araichi);
        $this->postJson('/api/v1/pos/vouchers/redeem', ['qr' => $this->voucherQr($voucher), 'amount' => 50_000, 'reference' => 'A-1'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.voucher.0', 'Minimal belanja untuk voucher ini Rp100.000.');

        $voucher->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->postJson('/api/v1/pos/vouchers/redeem', ['qr' => $this->voucherQr($voucher), 'amount' => 200_000, 'reference' => 'A-2'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.voucher.0', 'Voucher sudah kedaluwarsa.');

        $this->assertNull($voucher->fresh()->used_at);
    }

    public function test_one_receipt_cannot_use_two_vouchers(): void
    {
        $first = $this->promo();
        $second = $this->promo(['title' => 'Voucher kedua']);
        $this->claim($first)->assertCreated();
        $this->claim($second)->assertCreated();
        [$a, $b] = $this->member->vouchers()->orderBy('id')->get()->all();

        $this->actAsCashier($this->araichi);
        $this->postJson('/api/v1/pos/vouchers/redeem', ['qr' => $this->voucherQr($a), 'amount' => 200_000, 'reference' => 'S-1'])->assertOk();
        $this->postJson('/api/v1/pos/vouchers/redeem', ['qr' => $this->voucherQr($b), 'amount' => 200_000, 'reference' => 'S-1'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.voucher.0', 'Struk ini sudah memakai voucher lain.');
    }

    public function test_voucher_qr_is_signed_short_lived_and_owner_only(): void
    {
        $this->claim($this->promo())->assertCreated();
        $voucher = $this->member->vouchers()->first();

        $this->getJson("/api/v1/me/vouchers/{$voucher->id}/qr")->assertOk()->assertJsonStructure(['payload', 'expires_at']);

        Sanctum::actingAs(User::factory()->create(), ['member']);
        $this->getJson("/api/v1/me/vouchers/{$voucher->id}")->assertNotFound();
        $this->getJson("/api/v1/me/vouchers/{$voucher->id}/qr")->assertNotFound();

        $this->actAsCashier($this->araichi);
        $payload = $this->voucherQr($voucher);
        $tampered = substr($payload, 0, -1).(str_ends_with($payload, '0') ? '1' : '0');
        $this->postJson('/api/v1/pos/vouchers/check', ['qr' => $tampered])->assertUnprocessable()->assertJsonPath('errors.qr.0', 'QR voucher tidak valid.');

        $this->travel(config('loyalty.qr_ttl') + 1)->seconds();
        $this->postJson('/api/v1/pos/vouchers/check', ['qr' => $payload])->assertUnprocessable()->assertJsonValidationErrors('qr');
    }
}
