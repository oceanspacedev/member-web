<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Outlet;
use App\Models\User;
use App\Services\MemberQr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosPointsTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $ulon;

    private Outlet $padelnis;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ulon = Outlet::create(['brand_id' => Brand::create(['name' => 'Ulon', 'slug' => 'ulon', 'category' => 'culinary'])->id, 'name' => 'Ulon Ciledug']);
        $this->padelnis = Outlet::create(['brand_id' => Brand::create(['name' => 'Padelnis', 'slug' => 'padelnis', 'category' => 'sports'])->id, 'name' => 'Padelnis']);
        $this->member = User::factory()->create();
    }

    private function actAsCashier(Outlet $outlet): User
    {
        $cashier = User::factory()->cashier($outlet->id)->create();
        Sanctum::actingAs($cashier, ['member', 'pos']);

        return $cashier;
    }

    private function qr(): string
    {
        return app(MemberQr::class)->issue($this->member)['payload'];
    }

    public function test_cashier_scans_member_qr_and_points_are_added(): void
    {
        $this->actAsCashier($this->ulon);

        $this->postJson('/api/v1/pos/scan', ['qr' => $this->qr()])
            ->assertOk()
            ->assertJsonPath('member.member_code', $this->member->member_code);

        // Silver 2%: Rp250.000 x 2% = Rp5.000 = 500 poin
        $this->postJson('/api/v1/pos/earn', ['qr' => $this->qr(), 'amount' => 250_000, 'reference' => 'STRUK-001'])
            ->assertCreated()
            ->assertJsonPath('transaction.points', 500)
            ->assertJsonPath('loyalty.points_balance', 500);
    }

    public function test_same_receipt_is_not_counted_twice(): void
    {
        $this->actAsCashier($this->ulon);
        $payload = ['qr' => $this->qr(), 'amount' => 100_000, 'reference' => 'STRUK-002'];

        $this->postJson('/api/v1/pos/earn', $payload)->assertCreated();
        $this->postJson('/api/v1/pos/earn', $payload)->assertOk();

        $this->assertSame(200, $this->member->fresh()->points_balance);
        $this->assertSame(1, $this->member->pointTransactions()->count());
    }

    public function test_tier_upgrades_and_cashback_follows_new_tier(): void
    {
        $this->actAsCashier($this->ulon);

        // Rp3.000.000 masih dihitung Silver (2%) lalu member naik ke Gold.
        $this->postJson('/api/v1/pos/earn', ['qr' => $this->qr(), 'amount' => 3_000_000, 'reference' => 'A'])
            ->assertJsonPath('transaction.points', 6_000)
            ->assertJsonPath('loyalty.tier', 'gold');

        // Transaksi berikutnya pakai cashback Gold 5%.
        $this->postJson('/api/v1/pos/earn', ['qr' => $this->qr(), 'amount' => 100_000, 'reference' => 'B'])
            ->assertJsonPath('transaction.points', 500)
            ->assertJsonPath('loyalty.next_tier', 'platinum');
    }

    public function test_points_earned_at_one_brand_are_redeemed_at_another(): void
    {
        $this->actAsCashier($this->ulon);
        $this->postJson('/api/v1/pos/earn', ['qr' => $this->qr(), 'amount' => 1_000_000, 'reference' => 'X'])->assertCreated();

        $this->actAsCashier($this->padelnis);
        $this->postJson('/api/v1/pos/redeem', ['qr' => $this->qr(), 'points' => 1_500, 'reference' => 'P-1'])
            ->assertCreated()
            ->assertJsonPath('transaction.points', -1_500)
            ->assertJsonPath('transaction.amount', 15_000)
            ->assertJsonPath('loyalty.points_balance', 500);

        $this->postJson('/api/v1/pos/redeem', ['qr' => $this->qr(), 'points' => 1_000, 'reference' => 'P-2'])
            ->assertJsonValidationErrors('points');
    }

    public function test_expired_or_tampered_qr_is_rejected(): void
    {
        $this->actAsCashier($this->ulon);
        $qr = $this->qr();

        // Ubah karakter terakhir tanda tangan (selalu berbeda dari aslinya).
        $tampered = substr($qr, 0, -1).(str_ends_with($qr, '0') ? '1' : '0');
        $this->postJson('/api/v1/pos/scan', ['qr' => $tampered])->assertJsonValidationErrors('qr');

        $this->travel(config('loyalty.qr_ttl') + 1)->seconds();
        $this->postJson('/api/v1/pos/scan', ['qr' => $qr])->assertJsonValidationErrors('qr');
    }

    public function test_members_cannot_use_pos_endpoints(): void
    {
        Sanctum::actingAs($this->member, ['member']);

        $this->postJson('/api/v1/pos/earn', ['qr' => $this->qr(), 'amount' => 100_000, 'reference' => 'Z'])->assertForbidden();
    }

    public function test_member_sees_point_history(): void
    {
        $this->actAsCashier($this->ulon);
        $this->postJson('/api/v1/pos/earn', ['qr' => $this->qr(), 'amount' => 50_000, 'reference' => 'H'])->assertCreated();

        Sanctum::actingAs($this->member->fresh(), ['member']);
        $this->getJson('/api/v1/me/points')
            ->assertOk()
            ->assertJsonPath('data.0.outlet.name', 'Ulon Ciledug')
            ->assertJsonPath('loyalty.points_balance', 100);

        $this->getJson('/api/v1/me/qr')->assertOk()->assertJsonStructure(['payload', 'expires_at']);
    }
}
