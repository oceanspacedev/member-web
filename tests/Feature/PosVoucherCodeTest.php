<?php

namespace Tests\Feature;

use App\Filament\Resources\Promos\Pages\EditPromo;
use App\Filament\Resources\Promos\Pages\ListPromos;
use App\Filament\Resources\Promos\RelationManagers\CodesRelationManager;
use App\Models\Brand;
use App\Models\Outlet;
use App\Models\Promo;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Services\VoucherCodeImporter;
use DateTimeImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Voucher dari POS outlet (mis. daftar voucher POS Ulon): admin memilih outlet + file, promo dibuat otomatis
 * per nilai diskon, kode dibagikan satu per member, dan impor ulang file POS terbaru menandai yang sudah dipakai.
 */
class PosVoucherCodeTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $brand = Brand::create(['name' => 'Ulon', 'slug' => 'ulon', 'category' => 'culinary']);
        $this->outlet = Outlet::create(['brand_id' => $brand->id, 'name' => 'Ulon Ciledug', 'city' => 'Cirebon']);
    }

    /** Kolom daftar voucher di POS Ulon. */
    private const POS_HEADERS = ['Kode', 'Judul', 'Diskon', 'Mulai', 'Berakhir', 'Kadaluarsa', 'Penggunaan'];

    /** File .xlsx seperti hasil ekspor daftar voucher POS (kolom Kode, Judul, Diskon, ...). */
    private function xlsx(array $rows, array $headers = self::POS_HEADERS): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pos').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Daftar Voucher — diekspor dari POS']));
        $writer->addRow(Row::fromValues($headers));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return $path;
    }

    private function import(string $path, string $extension = 'xlsx', array $options = [], ?Outlet $outlet = null): array
    {
        return app(VoucherCodeImporter::class)->import($outlet ?? $this->outlet, $path, $extension, $options);
    }

    private function row(string $code, string $discount = 'IDR 5.000', string $usage = '0/1', ?string $expires = null): array
    {
        return [$code, 'DISC 5K', $discount, null, null, $expires, $usage];
    }

    public function test_importing_a_pos_export_creates_a_promo_per_discount_value_and_reports_skips(): void
    {
        $start = new DateTimeImmutable('2026-07-31');
        $summary = $this->import($this->xlsx([
            ['TQQ02A5XDYJ2', 'VOUCHER YAMAHA # 2', '100%', $start, null, null, '0/10'],
            ['EU1VCSDFDFZO', 'DISC 5K 40', 'IDR 5.000', $start, null, null, '0/1'],
            ['SY9RLW1ZD6L1', 'DISC 5K 39', 'IDR 5.000', $start, null, null, '0/1'],
            ['DISC10K01', 'DISC 10K 01', 'IDR 10.000', $start, null, null, '0/1'],
            ['PERSEN10', 'DISC 10%', '10%', $start, null, null, '0/1'],
            ['SUDAHPAKAI1', 'DISC 5K 01', 'IDR 5.000', $start, null, null, '1/1'],
            ['LAMA0001', 'DISC 5K 02', 'IDR 5.000', $start, null, now()->subDays(2)->toDateString(), '0/1'],
            ['TANPANILAI', 'DISC ?', '', $start, null, null, '0/1'],
            ['eu1vcsdfdfzo', 'DISC 5K 40', 'IDR 5.000', $start, null, null, '0/1'],
            [null, null, null, null, null, null, null],
        ]));

        $this->assertSame(3, $summary['created']);
        $this->assertSame(['TQQ02A5XDYJ2'], $summary['skipped'][VoucherCodeImporter::SKIP_MULTI_USE]);
        $this->assertSame(['PERSEN10'], $summary['skipped'][VoucherCodeImporter::SKIP_PERCENT]);
        $this->assertSame(['SUDAHPAKAI1'], $summary['skipped'][VoucherCodeImporter::SKIP_USED]);
        $this->assertSame(['LAMA0001'], $summary['skipped'][VoucherCodeImporter::SKIP_EXPIRED]);
        $this->assertSame(['TANPANILAI'], $summary['skipped'][VoucherCodeImporter::SKIP_NO_VALUE]);
        $this->assertSame(['EU1VCSDFDFZO'], $summary['skipped'][VoucherCodeImporter::SKIP_DUPLICATE]);

        // Satu promo per nilai diskon, langsung tayang sebagai klaim gratis di outlet itu.
        $five = Promo::where('voucher_value', 5000)->sole();
        $this->assertSame('Voucher Rp5.000 – Ulon Ciledug', $five->title);
        $this->assertSame('Rp5rb', $five->highlight);
        $this->assertSame(Promo::TYPE_CLAIM, $five->type);
        $this->assertSame($this->outlet->id, $five->pos_outlet_id);
        $this->assertSame($this->outlet->brand_id, $five->brand_id);
        $this->assertTrue($five->is_active);
        $this->assertSame(2, $five->quotaLeft());
        $this->assertSame('Rp10rb', Promo::where('voucher_value', 10000)->sole()->highlight);

        $code = VoucherCode::where('code', 'EU1VCSDFDFZO')->sole();
        $this->assertSame('DISC 5K 40', $code->pos_title);
        $this->assertSame('2026-07-31', $code->starts_on->toDateString()); // tanggal tanpa format tanggal di Excel tetap terbaca

        $text = VoucherCodeImporter::describe($summary);
        $this->assertStringContainsString('Promo baru "Voucher Rp5.000 – Ulon Ciledug": 2 kode.', $text);
        $this->assertStringContainsString('Dilewati 1 — '.VoucherCodeImporter::SKIP_MULTI_USE.': TQQ02A5XDYJ2.', $text);
    }

    public function test_a_later_import_adds_new_codes_to_the_running_promo_of_the_same_value(): void
    {
        $this->import($this->xlsx([$this->row('A0001'), $this->row('A0002')]));
        $this->travel(1)->hours();
        $summary = $this->import($this->xlsx([$this->row('A0002'), $this->row('A0003')]));

        // Promo yang menerima kode baru naik ke atas daftar promo.
        $this->assertTrue(Promo::sole()->updated_at->gt(now()->subMinute()));

        $this->assertSame(1, Promo::count());
        $this->assertSame(3, Promo::sole()->quotaLeft());
        $this->assertSame(1, $summary['updated']);
        $this->assertStringContainsString('Ditambahkan ke promo "Voucher Rp5.000 – Ulon Ciledug": 1 kode.', VoucherCodeImporter::describe($summary));
    }

    public function test_vouchers_can_be_offered_for_points_until_a_chosen_date(): void
    {
        $this->import($this->xlsx([$this->row('POIN0001')]), 'xlsx', ['type' => Promo::TYPE_SALE, 'price_points' => 500, 'ends_at' => '2099-01-31']);

        $promo = Promo::sole();
        $this->assertSame(Promo::TYPE_SALE, $promo->type);
        $this->assertSame(500, $promo->price_points);
        $this->assertSame('2099-01-31', $promo->ends_at->toDateString());
    }

    public function test_other_pos_formats_are_recognised_from_their_column_names(): void
    {
        // CSV dengan titik koma (Excel Indonesia) dan judul kolom bahasa Inggris dari POS lain.
        $path = tempnam(sys_get_temp_dir(), 'pos').'.csv';
        file_put_contents($path, "Voucher Code;Discount;Expired;Usage\nABC123;Rp10.000;31/12/2099;0/1\nDEF456;10000;2099-12-31;0\n");

        $summary = $this->import($path, 'csv');

        $this->assertSame(2, $summary['created']);
        $this->assertSame('2099-12-31', VoucherCode::where('code', 'ABC123')->sole()->expires_on->toDateString());
        $this->assertSame(10000, Promo::sole()->voucher_value);
    }

    public function test_a_file_without_a_code_column_explains_what_is_missing(): void
    {
        try {
            $this->import($this->xlsx([['X', 'Y']], ['Nama', 'Harga']));
            $this->fail('Seharusnya gagal.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Kolom kode voucher tidak ditemukan', $e->errors()['file'][0]);
            $this->assertStringContainsString('Nama, Harga', $e->errors()['file'][0]);
        }
        $this->assertSame(0, Promo::count());
    }

    public function test_each_member_receives_one_pos_code_and_the_quota_follows_the_stock(): void
    {
        $this->import($this->xlsx([$this->row('EU1VCSDFDFZO', expires: '2099-01-31'), $this->row('SY9RLW1ZD6L1')]));
        $promo = Promo::sole();

        Sanctum::actingAs(User::factory()->create(), ['member']);
        $id = $this->postJson("/api/v1/promos/{$promo->id}/vouchers")->assertCreated()->json('voucher.id');

        // Kode tampil persis seperti di POS (tanpa tanda hubung) dan berlaku di outlet POS-nya.
        $this->getJson("/api/v1/me/vouchers/{$id}")
            ->assertOk()
            ->assertJsonPath('data.code', 'EU1VCSDFDFZO')
            ->assertJsonPath('data.value', 5000)
            ->assertJsonPath('data.pos_outlet.name', 'Ulon Ciledug');
        $this->assertSame($id, VoucherCode::where('code', 'EU1VCSDFDFZO')->value('voucher_id'));

        Sanctum::actingAs(User::factory()->create(), ['member']);
        $this->postJson("/api/v1/promos/{$promo->id}/vouchers")->assertCreated()->assertJsonPath('voucher.code', 'SY9RLW1ZD6L1');

        $this->assertSame(0, $promo->fresh()->quotaLeft());
        Sanctum::actingAs(User::factory()->create(), ['member']);
        $this->postJson("/api/v1/promos/{$promo->id}/vouchers")->assertUnprocessable()->assertJsonPath('message', 'Kuota voucher sudah habis.');
    }

    public function test_reimporting_the_latest_pos_export_marks_codes_used_at_the_cashier(): void
    {
        $this->import($this->xlsx([$this->row('EU1VCSDFDFZO')]));
        Sanctum::actingAs(User::factory()->create(), ['member']);
        $id = $this->postJson('/api/v1/promos/'.Promo::sole()->id.'/vouchers')->assertCreated()->json('voucher.id');

        $summary = $this->import($this->xlsx([$this->row('EU1VCSDFDFZO', usage: '1/1')]));

        $this->assertSame(1, $summary['marked_used']);
        $this->getJson("/api/v1/me/vouchers/{$id}")->assertJsonPath('data.status', 'used')->assertJsonPath('data.used_outlet', 'Ulon Ciledug');
        $this->assertSame(VoucherCode::STATUS_USED, VoucherCode::sole()->status());
    }

    public function test_codes_of_another_outlet_or_clashing_with_space_club_codes_are_skipped(): void
    {
        $this->import($this->xlsx([$this->row('SAMA0001')]));
        $other = Outlet::create(['brand_id' => $this->outlet->brand_id, 'name' => 'Ulon Pekanbaru']);
        $voucher = Voucher::create(['user_id' => User::factory()->create()->id, 'code' => 'BENTROK01', 'source' => 'claim', 'title' => 'X', 'value' => 1000, 'expires_at' => now()->addDay()]);

        $summary = $this->import($this->xlsx([$this->row('SAMA0001'), $this->row($voucher->code)]), outlet: $other);

        $this->assertSame(0, $summary['created']);
        $this->assertSame(['SAMA0001'], $summary['skipped'][VoucherCodeImporter::SKIP_OTHER_OUTLET]);
        $this->assertSame(['BENTROK01'], $summary['skipped'][VoucherCodeImporter::SKIP_CLASH]);
        $this->assertSame(1, Promo::count()); // tidak ada promo kosong untuk outlet lain
    }

    public function test_admin_imports_from_the_promo_list_and_the_new_promo_appears_below(): void
    {
        Storage::fake('local');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['phone' => '6281200000003']);
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();
        $admin->grantSuperAdmin();
        $this->actingAs($admin);

        $file = UploadedFile::fake()->createWithContent('voucher-ulon.csv', "Kode,Judul,Diskon,Penggunaan\nEU1VCSDFDFZO,DISC 5K 40,IDR 5.000,0/1\nSY9RLW1ZD6L1,DISC 5K 39,IDR 5.000,0/1\n");

        Livewire::test(ListPromos::class)
            ->assertActionVisible('importPosVouchers')
            ->assertActionDoesNotExist('downloadPosTemplate')
            ->callAction('importPosVouchers', ['outlet_id' => $this->outlet->id, 'file' => $file, 'type' => Promo::TYPE_CLAIM, 'ends_at' => now()->addMonth()->toDateString()])
            ->assertNotified('Impor voucher POS selesai')
            ->assertCanSeeTableRecords(Promo::all());

        $promo = Promo::sole();
        $this->assertSame('Voucher Rp5.000 – Ulon Ciledug', $promo->title);
        $this->assertSame(2, $promo->quotaLeft());

        // Kode-kodenya terlihat di halaman promo.
        Livewire::test(CodesRelationManager::class, ['ownerRecord' => $promo, 'pageClass' => EditPromo::class])
            ->assertCanSeeTableRecords(VoucherCode::all());

        // Kolom "Kode POS" di daftar promo membuka tab daftar kode secara langsung.
        $codesUrl = EditPromo::getUrl(['record' => $promo, 'relation' => '0']);
        $this->get('/admin/promos')->assertOk()->assertSee(e($codesUrl), false)->assertSee('2 tersisa · Ulon Ciledug');
        $this->get($codesUrl)->assertOk()->assertSee('Detail promo')->assertSee('Kode voucher POS')->assertSee('EU1VCSDFDFZO')->assertSee('SY9RLW1ZD6L1');
    }
}
