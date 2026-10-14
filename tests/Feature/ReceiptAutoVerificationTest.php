<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Outlet;
use App\Models\PointTransaction;
use App\Models\ReceiptClaim;
use App\Models\User;
use App\Services\LoyaltyService;
use App\Services\Receipts\ReceiptReader;
use App\Services\Receipts\ReceiptReaderUnavailable;
use App\Services\Receipts\ReceiptReading;
use App\Services\Receipts\ReceiptVerifier;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sistem first: foto struk dibaca otomatis, lalu poin masuk, ditolak dengan alasan yang jelas,
 * atau diserahkan ke admin bila meragukan. Member tidak memilih outlet.
 */
class ReceiptAutoVerificationTest extends TestCase
{
    use RefreshDatabase;

    /** Hasil baca yang dikembalikan pembaca tiruan; null = pembaca gagal. */
    private ?array $reading = null;

    /** @var list<string> */
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
                $this->sent[] = $message;
            }
        });

        $test = $this;
        $this->app->instance(ReceiptReader::class, new class($test) implements ReceiptReader
        {
            public function __construct(private ReceiptAutoVerificationTest $test) {}

            public function read(string $imageBytes, string $mimeType, Collection $outlets): ReceiptReading
            {
                $data = $this->test->currentReading() ?? throw new ReceiptReaderUnavailable('layanan mati');

                return ReceiptReading::fromArray($data);
            }
        });

        $brand = Brand::create(['name' => 'J Chicken', 'slug' => 'j-chicken', 'category' => 'fastfood']);
        $this->outlet = Outlet::create(['brand_id' => $brand->id, 'name' => 'J Chicken Ciledug']);
        $this->member = User::factory()->create(['name' => 'Rina', 'phone' => '6281299990000']);
    }

    public function currentReading(): ?array
    {
        return $this->reading;
    }

    /** Struk yang terbaca jelas dari outlet yang dikenal, dibeli hari ini. */
    private function clearReceipt(array $overrides = []): array
    {
        return $overrides + [
            'is_receipt' => true,
            'legible' => true,
            'outlet_id' => $this->outlet->id,
            'merchant_name' => 'J CHICKEN CILEDUG',
            'receipt_number' => 'INV-0001',
            'purchased_at' => now(config('loyalty.timezone'))->subHour()->format('Y-m-d H:i'),
            'total' => 150_000,
            'capture' => 'photo_of_paper',
            'tamper_suspected' => false,
            'tamper_signs' => [],
            'confidence' => 0.95,
            'notes' => null,
        ];
    }

    private function send(?array $reading, int $width = 600)
    {
        $this->reading = $reading;
        Sanctum::actingAs($this->member, ['member']);

        // Hanya foto: outlet dibaca dari struk.
        return $this->post('/api/v1/me/receipts', ['photo' => UploadedFile::fake()->image('struk.jpg', $width, 900)], ['Accept' => 'application/json']);
    }

    private function latest(): ReceiptClaim
    {
        return ReceiptClaim::latest('id')->firstOrFail();
    }

    public function test_a_clear_receipt_earns_points_automatically_without_choosing_an_outlet(): void
    {
        $this->send($this->clearReceipt())->assertCreated()->assertJsonPath('data.checking', true);

        $claim = $this->latest();
        $this->assertSame(ReceiptClaim::STATUS_APPROVED, $claim->status);
        $this->assertSame(ReceiptClaim::DECISION_AUTO_APPROVED, $claim->decision);
        $this->assertSame($this->outlet->id, $claim->outlet_id);
        $this->assertSame('INV-0001', $claim->receipt_number);
        $this->assertNull($claim->reviewed_by);

        // Silver 2%: Rp150.000 × 2% / Rp10 = 300 poin, langsung masuk.
        $this->assertSame(300, $this->member->fresh()->points_balance);
        $this->assertSame(300, PointTransaction::sole()->points);
        $this->assertStringContainsString('+300 poin', end($this->sent));

        // Aplikasi menunggu hasilnya lewat endpoint status.
        $this->getJson("/api/v1/me/receipts/{$claim->id}")
            ->assertOk()
            ->assertJsonPath('data.checking', false)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.points', 300)
            ->assertJsonPath('data.outlet.name', 'J Chicken Ciledug');
    }

    public function test_members_cannot_see_someone_elses_receipt(): void
    {
        $this->send($this->clearReceipt())->assertCreated();
        Sanctum::actingAs(User::factory()->create(), ['member']);

        $this->getJson("/api/v1/me/receipts/{$this->latest()->id}")->assertNotFound();
    }

    public function test_problems_the_member_can_fix_are_rejected_immediately_with_a_clear_reason(): void
    {
        $this->send(['is_receipt' => false] + $this->clearReceipt(), 601);
        $this->assertSame('Foto ini bukan struk belanja. Foto struk dari kasir outlet Ocean Space.', $this->latest()->reject_reason);

        $this->send($this->clearReceipt(['legible' => false, 'receipt_number' => null]), 602);
        $this->assertSame(ReceiptVerifier::UNREADABLE, $this->latest()->reject_reason);

        $this->send($this->clearReceipt(['purchased_at' => now(config('loyalty.timezone'))->subDays(9)->format('Y-m-d H:i'), 'receipt_number' => 'OLD-1']), 603);
        $this->assertStringContainsString('lebih dari 7 hari', $this->latest()->reject_reason);

        foreach (ReceiptClaim::all() as $claim) {
            $this->assertSame(ReceiptClaim::STATUS_REJECTED, $claim->status);
            $this->assertSame(ReceiptClaim::DECISION_AUTO_REJECTED, $claim->decision);
        }
        $this->assertSame(0, $this->member->fresh()->points_balance);
    }

    public function test_the_same_receipt_never_earns_twice_even_from_another_photo_or_account(): void
    {
        $this->send($this->clearReceipt(), 610);
        $this->assertSame(ReceiptClaim::STATUS_APPROVED, $this->latest()->status);

        // Foto lain dari struk yang sama (nomor ditulis beda spasi/huruf).
        $this->send($this->clearReceipt(['receipt_number' => 'inv-0001 ']), 611);
        $this->assertSame('Struk ini sudah pernah mendapat poin.', $this->latest()->reject_reason);

        // Sudah tercatat lewat kasir.
        app(LoyaltyService::class)->earn($this->member, $this->outlet, 50_000, 'KASIR-9');
        $this->send($this->clearReceipt(['receipt_number' => 'kasir-9']), 612);
        $this->assertSame('Struk ini sudah pernah mendapat poin.', $this->latest()->reject_reason);

        $this->assertSame(2, PointTransaction::count());
    }

    public function test_suspicious_receipts_go_to_the_admin_with_the_reasons_and_prefilled_data(): void
    {
        config(['loyalty.receipts.daily_limit' => 10]);
        $cases = [
            'Tanda editan' => $this->clearReceipt(['receipt_number' => 'A-1', 'tamper_suspected' => true, 'tamper_signs' => ['Angka total memakai font berbeda']]),
            'Tangkapan layar' => $this->clearReceipt(['receipt_number' => 'A-2', 'capture' => 'screenshot']),
            'Keyakinan baca rendah' => $this->clearReceipt(['receipt_number' => 'A-3', 'confidence' => 0.5]),
            'Outlet tidak dikenali' => $this->clearReceipt(['receipt_number' => 'A-4', 'outlet_id' => null, 'merchant_name' => 'Warung Lain']),
            'Total di atas batas' => $this->clearReceipt(['receipt_number' => 'A-5', 'total' => 5_000_000]),
            'setelah tanggal kirim' => $this->clearReceipt(['receipt_number' => 'A-6', 'purchased_at' => now(config('loyalty.timezone'))->addDays(3)->format('Y-m-d')]),
        ];

        $width = 620;
        foreach ($cases as $expected => $reading) {
            $this->send($reading, $width++)->assertCreated();
            $claim = $this->latest();

            $this->assertSame(ReceiptClaim::STATUS_PENDING, $claim->status, $expected);
            $this->assertSame(ReceiptClaim::DECISION_NEEDS_REVIEW, $claim->decision, $expected);
            $this->assertStringContainsString($expected, implode(' ', $claim->flags), $expected);
            // Isian awal untuk admin.
            $this->assertSame($reading['total'], $claim->amount);
        }

        $this->assertSame(0, $this->member->fresh()->points_balance);
        $this->getJson("/api/v1/me/receipts/{$claim->id}")->assertJsonPath('data.in_review', true);
    }

    public function test_many_claims_at_one_outlet_on_one_day_are_checked_by_an_admin(): void
    {
        config(['loyalty.receipts.auto_per_outlet_per_day' => 2]);

        $this->send($this->clearReceipt(['receipt_number' => 'D-1']), 640);
        $this->send($this->clearReceipt(['receipt_number' => 'D-2']), 641);
        $this->send($this->clearReceipt(['receipt_number' => 'D-3']), 642);

        $this->assertSame(2, ReceiptClaim::where('status', ReceiptClaim::STATUS_APPROVED)->count());
        $this->assertSame(ReceiptClaim::DECISION_NEEDS_REVIEW, $this->latest()->decision);
    }

    public function test_when_the_reader_is_down_the_receipt_waits_for_an_admin_instead_of_failing(): void
    {
        $this->send(null)->assertCreated();

        $claim = $this->latest();
        $this->assertSame(ReceiptClaim::STATUS_PENDING, $claim->status);
        $this->assertSame(ReceiptClaim::DECISION_NEEDS_REVIEW, $claim->decision);
        $this->assertFalse($claim->isChecking());
    }
}
