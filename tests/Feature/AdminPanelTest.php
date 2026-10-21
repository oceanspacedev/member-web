<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\WhatsAppLogin;
use App\Filament\Resources\Outlets\Pages\EditOutlet;
use App\Filament\Resources\Promos\Pages\CreatePromo;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Vouchers\Pages\ListVouchers;
use App\Models\Brand;
use App\Models\OtpCode;
use App\Models\Outlet;
use App\Models\Promo;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppSender;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Panel admin (web) dan aplikasi mobile memakai database yang sama:
 * perubahan di satu sisi harus langsung terlihat di sisi lain.
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{phone: string, message: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Login WhatsApp di panel hanya muncul bila WAGHub dikonfigurasi; pengirimnya tetap palsu di bawah.
        config(['loyalty.whatsapp.waghub.token' => 'token-tes']);

        $this->app->instance(WhatsAppSender::class, new class($this->sent) implements WhatsAppSender
        {
            public function __construct(private array &$sent) {}

            public function send(string $phone, string $message, string $purpose = 'notification'): void
            {
                $this->sent[] = compact('phone', 'message');
            }
        });

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['phone' => '6281200000003', 'name' => 'Admin']);
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();
        $admin->grantSuperAdmin();

        return $admin;
    }

    private function lastCode(): string
    {
        preg_match('/\*(\d{6})\*/', end($this->sent)['message'], $m);

        return $m[1];
    }

    public function test_panel_access_is_limited_to_admins(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');

        $member = User::factory()->create();
        $this->actingAs($member)->get('/admin')->assertForbidden();

        $this->actingAs($this->admin())->get('/admin')->assertOk()->assertSee('Ocean Space');
    }

    public function test_whatsapp_login_is_hidden_until_waghub_is_configured(): void
    {
        config(['loyalty.whatsapp.waghub.token' => '']);

        $this->get('/admin/login')->assertOk()->assertDontSee('Atau masuk dengan');
        $this->get('/admin/login/whatsapp')->assertRedirect('/admin/login');
    }

    public function test_login_page_links_to_separate_whatsapp_login_page(): void
    {
        $this->get('/admin/login')->assertOk()
            ->assertSee('Atau masuk dengan')
            ->assertSee(route('filament.admin.auth.whatsapp-login'), escape: false);

        $this->get('/admin/login/whatsapp')->assertOk()->assertSee('Masuk dengan WhatsApp');
        $this->actingAs($this->admin())->get('/admin/login/whatsapp')->assertRedirect('/admin');
    }

    public function test_admin_logs_in_with_whatsapp_otp(): void
    {
        $admin = $this->admin();

        $page = Livewire::test(WhatsAppLogin::class)
            ->fillForm(['phone' => '0812-0000-0003'])
            ->call('submit')
            ->assertSet('awaitingOtp', true);

        $this->assertSame('6281200000003', $this->sent[0]['phone']);

        $page->fillForm(['code' => $this->lastCode()])
            ->call('submit')
            ->assertRedirect();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_otp_code_with_leading_zero_is_accepted(): void
    {
        $admin = $this->admin();

        $page = Livewire::test(WhatsAppLogin::class)
            ->fillForm(['phone' => '081200000003'])
            ->call('submit');

        // Ganti kode yang terkirim dengan kode berawalan 0.
        OtpCode::where('phone', '6281200000003')->latest('id')->first()
            ->forceFill(['code_hash' => Hash::make('012345')])->save();

        $page->fillForm(['code' => '012345'])
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_non_admin_numbers_get_no_otp_and_cannot_log_in(): void
    {
        User::factory()->create(['phone' => '6281299990000']);

        Livewire::test(WhatsAppLogin::class)
            ->fillForm(['phone' => '081299990000'])
            ->call('submit')
            ->assertSet('awaitingOtp', true)
            ->fillForm(['code' => '123456'])
            ->call('submit')
            ->assertHasFormErrors(['code']);

        $this->assertSame([], $this->sent);
        $this->assertGuest();
    }

    public function test_admin_logs_in_with_email_and_password(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['email' => 'admin@lifestyleclub.test', 'password' => 'rahasia-panel-123'])->save();

        Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@lifestyleclub.test', 'password' => 'rahasia-panel-123'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_wrong_password_and_non_admin_emails_are_rejected(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['email' => 'admin@lifestyleclub.test', 'password' => 'rahasia-panel-123'])->save();
        User::factory()->create(['email' => 'member@contoh.com', 'password' => 'password-member-1']);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@lifestyleclub.test', 'password' => 'salah'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'member@contoh.com', 'password' => 'password-member-1'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_seeder_gives_demo_admin_email_login_from_env(): void
    {
        config(['loyalty.admin.email' => 'admin@lifestyleclub.test', 'loyalty.admin.password' => 'rahasia-dari-env-1']);
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@lifestyleclub.test')->firstOrFail();
        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertSame('6281200000003', $admin->phone);
        $this->assertTrue(Hash::check('rahasia-dari-env-1', $admin->password));
        $this->assertTrue($admin->isSuperAdmin());
        // Daftar izin Shield ikut dibuat agar menu Peran & izin langsung terisi.
        $this->assertTrue(Permission::where('name', 'ViewAny:Brand')->exists());
        $this->assertTrue(Permission::where('name', 'View:Horizon')->exists());
    }

    public function test_admin_create_command_makes_an_admin_with_email_and_whatsapp(): void
    {
        $this->artisan('admin:create', ['email' => 'Owner@Contoh.com', '--name' => 'Pemilik', '--phone' => '0812-7777-0000'])
            ->expectsQuestion('Password (min. 10 karakter)', 'password-kuat-123')
            ->expectsQuestion('Ulangi password', 'password-kuat-123')
            ->assertSuccessful();

        $owner = User::where('email', 'owner@contoh.com')->firstOrFail();
        $this->assertSame(User::ROLE_ADMIN, $owner->role);
        $this->assertTrue($owner->isSuperAdmin());
        $this->assertSame('6281277770000', $owner->phone);
        $this->assertTrue(Hash::check('password-kuat-123', $owner->password));

        $this->artisan('admin:create', ['email' => 'lemah@contoh.com'])
            ->expectsQuestion('Password (min. 10 karakter)', 'pendek')
            ->expectsQuestion('Ulangi password', 'pendek')
            ->assertFailed();
    }

    public function test_promo_created_on_web_appears_in_the_app(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreatePromo::class)
            ->fillForm([
                'title' => 'Diskon 30% semua ramen',
                'highlight' => '30%',
                'starts_at' => now()->toDateString(),
                'ends_at' => now()->addWeek()->toDateString(),
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->getJson('/api/v1/promos')->assertJsonFragment(['title' => 'Diskon 30% semua ramen', 'is_universal' => true]);
    }

    public function test_promo_banner_with_text_in_the_image_needs_no_highlight(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreatePromo::class)
            ->fillForm([
                'title' => 'Es teh gratis tiap Jumat',
                'starts_at' => now()->toDateString(),
                'ends_at' => now()->addWeek()->toDateString(),
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->getJson('/api/v1/promos')->assertJsonFragment(['title' => 'Es teh gratis tiap Jumat', 'highlight' => null]);
    }

    public function test_voucher_promo_made_on_web_is_claimed_in_the_app_and_listed_in_the_panel(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        // Tukar poin tanpa harga poin ditolak.
        Livewire::test(CreatePromo::class)
            ->fillForm(['type' => 'sale', 'title' => 'X', 'highlight' => 'X', 'voucher_value' => 50_000, 'starts_at' => now()->toDateString(), 'ends_at' => now()->addWeek()->toDateString()])
            ->call('create')
            ->assertHasFormErrors(['price_points' => 'required']);

        Livewire::test(CreatePromo::class)
            ->fillForm([
                'type' => 'claim',
                'title' => 'Voucher Rp20.000 semua outlet',
                'highlight' => 'Rp20rb',
                'voucher_value' => 20_000,
                'min_spend' => 100_000,
                'quota' => 10,
                'per_member_limit' => 1,
                'starts_at' => now()->toDateString(),
                'ends_at' => now()->addWeek()->toDateString(),
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $promo = Promo::where('title', 'Voucher Rp20.000 semua outlet')->firstOrFail();
        $member = User::factory()->create();
        Sanctum::actingAs($member, ['member']);
        $this->postJson("/api/v1/promos/{$promo->id}/vouchers")->assertCreated();

        $this->actingAs($admin, 'web');
        Livewire::test(ListVouchers::class)
            ->assertCanSeeTableRecords($member->vouchers()->get());
        $this->get('/admin/vouchers')->assertOk();
        $this->get('/admin/pembayaran-voucher')->assertOk();
    }

    public function test_outlet_hidden_on_web_disappears_from_the_app(): void
    {
        $this->actingAs($this->admin());
        $brand = Brand::create(['name' => 'Ulon', 'slug' => 'ulon', 'category' => 'culinary']);
        $outlet = Outlet::create(['brand_id' => $brand->id, 'name' => 'Ulon Ciledug']);

        Livewire::test(EditOutlet::class, ['record' => $outlet->getRouteKey()])
            ->fillForm(['name' => 'Ulon Ciledug Baru', 'opening_hours.mon' => '09:00-21:00', 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('09:00-21:00', $outlet->fresh()->opening_hours['mon']);
        $this->getJson('/api/v1/outlets')->assertJsonMissing(['name' => 'Ulon Ciledug Baru']);
        $this->getJson("/api/v1/outlets/{$outlet->id}")->assertNotFound();
    }

    public function test_invalid_opening_hours_are_rejected(): void
    {
        $this->actingAs($this->admin());
        $outlet = Outlet::create(['brand_id' => Brand::create(['name' => 'X', 'slug' => 'x', 'category' => 'retail'])->id, 'name' => 'X']);

        Livewire::test(EditOutlet::class, ['record' => $outlet->getRouteKey()])
            ->fillForm(['opening_hours.mon' => 'jam 10 pagi'])
            ->call('save')
            ->assertHasFormErrors(['opening_hours.mon' => 'regex']);
    }

    public function test_points_adjusted_on_web_show_up_in_the_members_app(): void
    {
        $member = User::factory()->create();
        $this->actingAs($this->admin());

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('adjustPoints')->table($member), ['points' => 1500, 'reason' => 'Kompensasi struk'])
            ->assertHasNoFormErrors();

        $this->assertSame(1500, $member->fresh()->points_balance);

        Sanctum::actingAs($member->fresh(), ['member']);
        $this->getJson('/api/v1/me/points')
            ->assertJsonPath('data.0.type', 'adjust')
            ->assertJsonPath('data.0.points', 1500)
            ->assertJsonPath('loyalty.points_balance', 1500);
    }

    public function test_purchase_recorded_on_web_earns_points_like_the_cashier(): void
    {
        $member = User::factory()->create();
        $outlet = Outlet::create(['brand_id' => Brand::create(['name' => 'Ulon', 'slug' => 'ulon', 'category' => 'culinary'])->id, 'name' => 'Ulon Ciledug']);
        $this->actingAs($this->admin());

        // Silver 2%: Rp250.000 → Rp5.000 → 500 poin.
        $data = ['outlet_id' => $outlet->id, 'amount' => 250_000, 'reference' => 'STRUK-WEB-1'];
        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('recordPurchase')->table($member), $data)
            ->assertHasNoFormErrors();
        // Struk yang sama tidak menambah poin lagi.
        Livewire::test(ListUsers::class)->callAction(TestAction::make('recordPurchase')->table($member), $data);

        $this->assertSame(500, $member->fresh()->points_balance);
        $this->assertSame(1, $member->pointTransactions()->count());

        Sanctum::actingAs($member->fresh(), ['member']);
        $this->getJson('/api/v1/me/points')
            ->assertJsonPath('data.0.type', 'earn')
            ->assertJsonPath('data.0.amount', 250_000)
            ->assertJsonPath('loyalty.annual_spend', 250_000);
    }

    public function test_adjustment_cannot_push_balance_below_zero(): void
    {
        $member = User::factory()->create();
        $this->actingAs($this->admin());

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('adjustPoints')->table($member), ['points' => -100, 'reason' => 'Koreksi']);

        $this->assertSame(0, $member->fresh()->points_balance);
        $this->assertSame(0, $member->pointTransactions()->count());
    }

    public function test_avatar_uploaded_in_the_app_shows_in_the_admin_member_list(): void
    {
        Storage::fake('media');
        $member = User::factory()->create();

        Sanctum::actingAs($member, ['member']);
        $this->post('/api/v1/me/avatar', ['file' => UploadedFile::fake()->image('saya.jpg', 400, 400)], ['Accept' => 'application/json'])->assertOk();
        $path = $member->fresh()->avatar_path;

        // Sanctum::actingAs memindah guard default; panel memakai guard web.
        $this->actingAs($this->admin(), 'web');
        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$member])
            ->assertTableColumnStateSet('avatar_path', $path, record: $member);
    }

    public function test_admin_promotes_member_to_cashier_with_outlet(): void
    {
        $outlet = Outlet::create(['brand_id' => Brand::create(['name' => 'J', 'slug' => 'j', 'category' => 'fastfood'])->id, 'name' => 'J Chicken']);
        $member = User::factory()->create();
        $this->actingAs($this->admin());

        Livewire::test(EditUser::class, ['record' => $member->getRouteKey()])
            ->fillForm(['role' => User::ROLE_CASHIER, 'outlet_id' => $outlet->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $member->refresh();
        $this->assertSame(User::ROLE_CASHIER, $member->role);
        $this->assertSame($outlet->id, $member->outlet_id);
    }

    public function test_member_menu_lists_members_and_cashiers_but_not_admins(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$member])
            ->assertCanNotSeeTableRecords([$admin]);

        // Akun admin diubah lewat Pengguna panel, bukan lewat menu Member.
        $this->get("/admin/users/{$admin->getRouteKey()}/edit")->assertNotFound();
    }
}
