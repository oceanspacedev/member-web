<?php

namespace Tests\Feature;

use App\Filament\Resources\PartnerKeys\Pages\ListPartnerKeys;
use App\Models\Brand;
use App\Models\Outlet;
use App\Models\PartnerKey;
use App\Models\PointTransaction;
use App\Models\User;
use App\Models\Voucher;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * API partner POS (iSeller, Moka, Majoo, Olsera, ...): Space Club sebagai validator voucher.
 */
class PartnerApiTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $araichi;

    private Outlet $padelnis;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->araichi = Outlet::create(['brand_id' => Brand::create(['name' => 'Araichi Ramen', 'slug' => 'araichi-ramen', 'category' => 'culinary'])->id, 'name' => 'Araichi Ciledug']);
        $this->padelnis = Outlet::create(['brand_id' => Brand::create(['name' => 'Padelnis', 'slug' => 'padelnis', 'category' => 'sports'])->id, 'name' => 'Padelnis']);
        $this->member = User::factory()->create(['name' => 'Rina', 'phone' => '6281299990000']);
    }

    private function voucher(array $attributes = []): Voucher
    {
        return Voucher::create($attributes + [
            'user_id' => $this->member->id,
            'code' => 'ABCDEFGHJK',
            'source' => Voucher::SOURCE_CLAIM,
            'title' => 'Voucher Rp25.000 Araichi',
            'brand_id' => $this->araichi->brand_id,
            'value' => 25_000,
            'min_spend' => 100_000,
            'expires_at' => now()->addWeek(),
        ]);
    }

    /** @return array<string, string> */
    private function headers(Outlet $outlet): array
    {
        [, $plain] = PartnerKey::issue('iSeller '.$outlet->name, $outlet);

        return ['Authorization' => "Bearer {$plain}"];
    }

    public function test_requests_need_an_active_key_of_an_active_outlet(): void
    {
        $this->getJson('/api/partner/v1/outlet')->assertUnauthorized();
        $this->getJson('/api/partner/v1/outlet', ['Authorization' => 'Bearer osk_salah'])->assertUnauthorized();

        // Token aplikasi member tidak berlaku di API partner.
        Sanctum::actingAs($this->member, ['member']);
        $this->getJson('/api/partner/v1/outlet')->assertUnauthorized();

        [$key, $plain] = PartnerKey::issue('Moka', $this->araichi);
        $headers = ['Authorization' => "Bearer {$plain}"];

        $this->getJson('/api/partner/v1/outlet', $headers)
            ->assertOk()
            ->assertJsonPath('outlet.name', 'Araichi Ciledug')
            ->assertJsonPath('outlet.brand', 'Araichi Ramen');

        $this->assertNotNull($key->fresh()->last_used_at);
        $this->assertNotSame($plain, $key->key_hash);

        $key->revoke();
        $this->getJson('/api/partner/v1/outlet', $headers)->assertUnauthorized();

        [, $plain] = PartnerKey::issue('Majoo', $this->padelnis);
        $this->padelnis->update(['is_active' => false]);
        $this->getJson('/api/partner/v1/outlet', ['Authorization' => "Bearer {$plain}"])->assertUnauthorized();
    }

    public function test_check_validates_code_brand_and_min_spend_without_using_the_voucher(): void
    {
        $voucher = $this->voucher();
        $headers = $this->headers($this->araichi);

        // Kode boleh huruf kecil / dengan spasi atau tanda hubung.
        $this->postJson('/api/partner/v1/vouchers/check', ['code' => 'abcde-fghjk', 'amount' => 150_000], $headers)
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('problem', null)
            ->assertJsonPath('discount', 25_000)
            ->assertJsonPath('voucher.code', 'ABCDE-FGHJK')
            ->assertJsonPath('voucher.status', 'active')
            // Data pribadi member tidak dikirim ke POS pihak ketiga.
            ->assertJsonMissingPath('member')
            ->assertDontSee('6281299990000');

        $this->postJson('/api/partner/v1/vouchers/check', ['code' => 'ABCDE FGHJK', 'amount' => 50_000], $headers)
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('problem', 'Minimal belanja untuk voucher ini Rp100.000.')
            // Tidak valid → tidak ada potongan yang boleh dipasang POS.
            ->assertJsonPath('discount', null);

        $this->postJson('/api/partner/v1/vouchers/check', ['code' => 'ABCDEFGHJK'], $this->headers($this->padelnis))
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('problem', 'Voucher ini hanya berlaku di Araichi Ramen.');

        $this->postJson('/api/partner/v1/vouchers/check', ['code' => 'ZZZZZ-ZZZZZ'], $headers)->assertNotFound();

        $this->assertNull($voucher->fresh()->used_at);
    }

    public function test_redeem_uses_the_voucher_once_per_receipt_and_retries_are_safe(): void
    {
        $voucher = $this->voucher();
        $headers = $this->headers($this->araichi);
        $payload = ['code' => 'ABCDE-FGHJK', 'amount' => 150_000, 'reference' => 'INV-001'];

        $this->postJson('/api/partner/v1/vouchers/redeem', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('discount', 25_000)
            ->assertJsonPath('pay_amount', 125_000)
            ->assertJsonPath('voucher.status', 'used')
            ->assertJsonPath('voucher.reference', 'INV-001');

        $used = $voucher->fresh();
        $this->assertSame($this->araichi->id, $used->outlet_id);
        $this->assertNotNull($used->partner_key_id);
        $this->assertNull($used->cashier_id);
        // Poin dari POS pihak ketiga belum dicatat (menyusul).
        $this->assertSame(0, PointTransaction::count());

        // Request ulang (koneksi POS putus) mengembalikan hasil yang sama.
        $this->postJson('/api/partner/v1/vouchers/redeem', $payload, $headers)->assertOk()->assertJsonPath('pay_amount', 125_000);

        // Dipakai lagi di struk lain → ditolak.
        $this->postJson('/api/partner/v1/vouchers/redeem', [...$payload, 'reference' => 'INV-002'], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('voucher');

        // Struk yang sama tidak boleh memakai voucher kedua.
        $this->voucher(['code' => 'KKKKKMMMMM']);
        $this->postJson('/api/partner/v1/vouchers/redeem', [...$payload, 'code' => 'KKKKK-MMMMM'], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('errors.voucher.0', 'Struk ini sudah memakai voucher lain.');
    }

    public function test_redeem_rejects_expired_vouchers_and_wrong_brand(): void
    {
        $this->voucher(['expires_at' => now()->subDay()]);

        $this->postJson('/api/partner/v1/vouchers/redeem', ['code' => 'ABCDEFGHJK', 'amount' => 150_000, 'reference' => 'X1'], $this->headers($this->araichi))
            ->assertUnprocessable()
            ->assertJsonPath('errors.voucher.0', 'Voucher sudah kedaluwarsa.');

        $this->voucher(['code' => 'PPPPPQQQQQ']);
        $this->postJson('/api/partner/v1/vouchers/redeem', ['code' => 'PPPPPQQQQQ', 'amount' => 150_000, 'reference' => 'X2'], $this->headers($this->padelnis))
            ->assertUnprocessable();
    }

    public function test_cancel_reactivates_a_voucher_only_for_the_same_receipt_outlet_and_window(): void
    {
        $voucher = $this->voucher();
        $headers = $this->headers($this->araichi);
        $this->postJson('/api/partner/v1/vouchers/redeem', ['code' => 'ABCDEFGHJK', 'amount' => 150_000, 'reference' => 'INV-9'], $headers)->assertOk();

        $this->postJson('/api/partner/v1/vouchers/cancel', ['code' => 'ABCDEFGHJK', 'reference' => 'INV-salah'], $headers)->assertUnprocessable();
        $this->postJson('/api/partner/v1/vouchers/cancel', ['code' => 'ABCDEFGHJK', 'reference' => 'INV-9'], $this->headers($this->padelnis))->assertUnprocessable();

        $this->postJson('/api/partner/v1/vouchers/cancel', ['code' => 'ABCDEFGHJK', 'reference' => 'INV-9'], $headers)
            ->assertOk()
            ->assertJsonPath('voucher.status', 'active');

        $fresh = $voucher->fresh();
        $this->assertNull($fresh->used_at);
        $this->assertNull($fresh->reference);
        $this->assertNull($fresh->partner_key_id);

        // Bisa dipakai lagi, lalu batas 24 jam berlaku.
        $this->postJson('/api/partner/v1/vouchers/redeem', ['code' => 'ABCDEFGHJK', 'amount' => 150_000, 'reference' => 'INV-10'], $headers)->assertOk();
        $this->travel(25)->hours();
        $this->postJson('/api/partner/v1/vouchers/cancel', ['code' => 'ABCDEFGHJK', 'reference' => 'INV-10'], $headers)->assertUnprocessable();
    }

    public function test_vouchers_redeemed_by_our_cashier_cannot_be_cancelled_from_a_pos(): void
    {
        $this->voucher()->forceFill(['used_at' => now(), 'outlet_id' => $this->araichi->id, 'reference' => 'STRUK-1', 'bill_amount' => 150_000, 'discount' => 25_000])->save();

        $this->postJson('/api/partner/v1/vouchers/cancel', ['code' => 'ABCDEFGHJK', 'reference' => 'STRUK-1'], $this->headers($this->araichi))
            ->assertUnprocessable()
            ->assertJsonPath('errors.voucher.0', 'Voucher ini dipakai lewat kasir Space Club dan tidak bisa dibatalkan dari POS.');
    }

    public function test_our_cashier_app_accepts_a_typed_voucher_code(): void
    {
        $this->voucher();
        Sanctum::actingAs(User::factory()->cashier($this->araichi->id)->create(), ['member', 'pos']);

        $this->postJson('/api/v1/pos/vouchers/check', ['qr' => 'abcde-fghjk'])
            ->assertOk()
            ->assertJsonPath('usable', true)
            ->assertJsonPath('member.name', 'Rina');

        $this->postJson('/api/v1/pos/vouchers/check', ['qr' => 'ZZZZZZZZZZ'])->assertUnprocessable()->assertJsonValidationErrors('qr');
    }

    public function test_admin_issues_a_key_shown_once_and_can_revoke_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();
        $admin->grantSuperAdmin();
        $this->actingAs($admin);

        Livewire::test(ListPartnerKeys::class)
            ->callAction('issue', ['outlet_id' => $this->araichi->id, 'name' => 'iSeller Araichi'])
            ->assertHasNoFormErrors()
            ->assertActionMounted('showKey');

        $key = PartnerKey::sole();
        $this->assertSame('iSeller Araichi', $key->name);
        $this->assertStringStartsWith('osk_', $key->key_prefix);
        $this->assertSame(64, strlen($key->key_hash));

        Livewire::test(ListPartnerKeys::class)->callAction(TestAction::make('revoke')->table($key));
        $this->assertFalse($key->fresh()->isActive());
    }
}
