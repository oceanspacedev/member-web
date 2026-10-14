<?php

namespace Tests\Feature;

use App\Filament\Resources\ReceiptClaims\Pages\ListReceiptClaims;
use App\Models\Brand;
use App\Models\Outlet;
use App\Models\PointTransaction;
use App\Models\ReceiptClaim;
use App\Models\User;
use App\Services\LoyaltyService;
use App\Services\WhatsApp\WhatsAppSender;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Poin dari foto struk: member mengirim foto struk dari outlet ber-POS pihak ketiga, admin memeriksa.
 */
class ReceiptClaimTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{phone: string, message: string}> */
    private array $sent = [];

    private Outlet $outlet;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->app->instance(WhatsAppSender::class, new class($this->sent) implements WhatsAppSender
        {
            public function __construct(private array &$sent) {}

            public function send(string $phone, string $message, string $purpose = 'notification'): void
            {
                $this->sent[] = compact('phone', 'message');
            }
        });

        $this->outlet = Outlet::create(['brand_id' => Brand::create(['name' => 'J Chicken', 'slug' => 'j-chicken', 'category' => 'fastfood'])->id, 'name' => 'J Chicken Ciledug']);
        $this->member = User::factory()->create(['name' => 'Rina', 'phone' => '6281299990000']);
    }

    /** Ukuran berbeda → isi file berbeda, agar tidak dianggap foto yang sama. */
    private function photo(int $width = 600): UploadedFile
    {
        return UploadedFile::fake()->image('struk.jpg', $width, 900);
    }

    private function submit(array $overrides = [], ?UploadedFile $photo = null)
    {
        Sanctum::actingAs($this->member, ['member']);

        return $this->post('/api/v1/me/receipts', $overrides + [
            'photo' => $photo ?? $this->photo(),
            'outlet_id' => $this->outlet->id,
        ], ['Accept' => 'application/json']);
    }

    /** Data yang diisi admin dari foto saat menyetujui. */
    private function approve(ReceiptClaim $claim, array $overrides = [])
    {
        return Livewire::test(ListReceiptClaims::class)
            ->callAction(TestAction::make('approve')->table($claim), $overrides + [
                'receipt_number' => 'inv 0001',
                'amount' => 150_000,
                'purchased_at' => now(config('loyalty.timezone'))->toDateString(),
            ]);
    }

    private function admin(): User
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();
        $admin->grantSuperAdmin();

        return $admin;
    }

    public function test_without_automatic_reading_every_receipt_goes_to_the_admin_queue(): void
    {
        $this->submit()
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.checking', true)
            ->assertJsonPath('data.receipt_number', null)
            ->assertJsonPath('data.amount', null)
            ->assertJsonPath('data.outlet.name', 'J Chicken Ciledug');

        $claim = ReceiptClaim::sole();
        Storage::disk('local')->assertExists($claim->photo_path);
        // Disimpan privat, bukan di folder media publik.
        $this->assertStringStartsWith('receipts/', $claim->photo_path);
        $this->assertSame(0, $this->member->fresh()->points_balance);

        // Tanpa kunci API, sistem langsung menyerahkan struk ke admin (tidak menggantung).
        $this->assertSame(ReceiptClaim::DECISION_NEEDS_REVIEW, $claim->decision);
        $this->assertNotNull($claim->verified_at);

        $this->getJson('/api/v1/me/receipts')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checking', false)
            ->assertJsonPath('data.0.in_review', true)
            ->assertJsonPath('data.0.status_label', 'Menunggu');
    }

    public function test_guests_cannot_submit_receipts(): void
    {
        $this->post('/api/v1/me/receipts', [], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_unknown_outlets_duplicate_photos_and_the_daily_limit_are_rejected(): void
    {
        $this->submit(['outlet_id' => 999])->assertJsonValidationErrors('outlet_id');

        $photo = $this->photo(700);
        $this->submit([], $photo)->assertCreated();
        $this->submit([], $photo)->assertJsonValidationErrors('photo');

        config(['loyalty.receipts.daily_limit' => 2]);
        $this->submit([], $this->photo(701))->assertCreated();
        $this->submit([], $this->photo(702))->assertJsonValidationErrors('photo');
    }

    public function test_admin_fills_the_receipt_data_and_points_follow_the_cashier_rules(): void
    {
        $this->submit()->assertCreated();
        $claim = ReceiptClaim::sole();
        $this->actingAs($admin = $this->admin(), 'web');

        $this->approve($claim)->assertHasNoFormErrors();

        $claim->refresh();
        $this->assertSame(ReceiptClaim::STATUS_APPROVED, $claim->status);
        $this->assertSame('INV0001', $claim->receipt_number);
        $this->assertSame(150_000, $claim->amount);
        $this->assertSame($admin->id, $claim->reviewed_by);

        // Silver cashback 2%, 1 poin = Rp10 → Rp150.000 × 2% / 10 = 300 poin.
        $transaction = PointTransaction::sole();
        $this->assertSame(300, $transaction->points);
        $this->assertSame('INV0001', $transaction->reference);
        $this->assertSame(300, $this->member->fresh()->points_balance);
        $this->assertStringContainsString('+300 poin', end($this->sent)['message']);

        $this->getJson('/api/v1/me/receipts')->assertJsonPath('data.0.points', 300);
    }

    public function test_the_same_receipt_number_only_earns_points_once(): void
    {
        $this->submit([], $this->photo(710))->assertCreated();
        $this->submit([], $this->photo(711))->assertCreated();
        [$first, $second] = ReceiptClaim::orderBy('id')->get()->all();
        $this->actingAs($this->admin(), 'web');

        $this->approve($first)->assertHasNoFormErrors();
        // Nomor yang sama (beda huruf/spasi) di outlet yang sama → ditolak.
        $this->approve($second, ['receipt_number' => 'INV0001'])->assertHasErrors();
        $this->assertTrue($second->fresh()->isPending());

        // Sudah tercatat lewat kasir juga tidak bisa.
        app(LoyaltyService::class)->earn($this->member, $this->outlet, 50_000, 'KASIR-9');
        $this->approve($second, ['receipt_number' => 'kasir-9'])->assertHasErrors();
        // Hanya klaim pertama + transaksi kasir.
        $this->assertSame(2, PointTransaction::count());
    }

    public function test_receipt_date_must_be_within_the_claim_window(): void
    {
        $this->submit()->assertCreated();
        $claim = ReceiptClaim::sole();
        $this->actingAs($this->admin(), 'web');

        $this->approve($claim, ['purchased_at' => now(config('loyalty.timezone'))->subDays(8)->toDateString()])->assertHasErrors();
        $this->assertTrue($claim->fresh()->isPending());

        // Batas dihitung dari tanggal kirim, bukan tanggal diperiksa: diperiksa 3 hari kemudian tetap sah.
        $this->travel(3)->days();
        $this->approve($claim, ['purchased_at' => now(config('loyalty.timezone'))->subDays(4)->toDateString()])->assertHasNoFormErrors();
    }

    public function test_admin_rejects_with_a_reason_and_the_member_can_resubmit(): void
    {
        $this->submit()->assertCreated();
        $claim = ReceiptClaim::sole();
        $this->actingAs($this->admin(), 'web');

        Livewire::test(ListReceiptClaims::class)
            ->callAction(TestAction::make('reject')->table($claim), ['reason' => 'Struk bukan dari outlet Ocean Space.'])
            ->assertHasNoFormErrors();

        $this->assertSame(ReceiptClaim::STATUS_REJECTED, $claim->fresh()->status);
        $this->assertSame(0, PointTransaction::count());
        $this->assertStringContainsString('Struk bukan dari outlet Ocean Space.', end($this->sent)['message']);

        // Setelah ditolak, struk yang sama boleh dikirim ulang dengan foto yang lebih jelas.
        $this->submit([], $this->photo(800))->assertCreated();
    }

    public function test_receipt_photos_are_only_visible_to_admins_allowed_to_view_claims(): void
    {
        $this->submit()->assertCreated();
        $claim = ReceiptClaim::sole();
        $url = route('receipt-photos.show', $claim);

        auth()->forgetGuards();
        auth()->shouldUse('web');
        $this->get($url)->assertRedirect('/admin/login');
        $this->actingAs($this->member, 'web')->get($url)->assertForbidden();
        $this->actingAs($this->admin(), 'web')->get($url)->assertOk();
    }
}
