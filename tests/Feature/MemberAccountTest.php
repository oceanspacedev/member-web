<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Widgets\LoyaltyStats;
use App\Models\Brand;
use App\Models\Outlet;
use App\Models\PointTransaction;
use App\Models\User;
use App\Models\Voucher;
use App\Services\LoyaltyService;
use App\Services\WhatsApp\WhatsAppSender;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Aktivitas member di panel (siapa yang login & memakai aplikasi) dan hapus akun.
 */
class MemberAccountTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{phone: string, message: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

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

    private function loginWithOtp(string $phone, string $device = 'ios-app'): array
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone])->assertOk();
        preg_match('/\*(\d{6})\*/', end($this->sent)['message'], $m);

        return $this->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'code' => $m[1], 'device_name' => $device])
            ->assertOk()
            ->json();
    }

    /** Sanctum menyimpan user di guard; dilupakan agar request berikutnya membaca token dari awal. */
    private function freshRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_login_in_the_app_shows_up_as_activity_in_the_panel(): void
    {
        $login = $this->loginWithOtp('081244710921', 'android-app');
        $member = User::find($login['user']['id']);

        $this->assertNotNull($member->last_login_at);
        $this->assertNotNull($member->last_seen_at);
        $this->assertSame('android-app', $member->last_device);
        $this->assertSame('Android', User::deviceLabel($member->last_device));

        $this->actingAs($this->admin(), 'web');
        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$member])
            ->assertTableColumnStateSet('tokens_count', 1, record: $member);
    }

    public function test_using_the_app_updates_last_seen_at_most_every_five_minutes(): void
    {
        $member = User::factory()->create();
        $token = $member->createToken('ios-app', ['member'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $first = $member->fresh()->last_seen_at;
        $this->assertNotNull($first);
        $this->assertSame('ios-app', $member->fresh()->last_device);

        $this->travel(2)->minutes();
        $this->freshRequest();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $this->assertTrue($member->fresh()->last_seen_at->eq($first));

        $this->travel(10)->minutes();
        $this->freshRequest();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $this->assertTrue($member->fresh()->last_seen_at->gt($first));
    }

    public function test_member_list_filters_by_activity(): void
    {
        $today = User::factory()->create(['name' => 'Aktif hari ini']);
        $today->forceFill(['last_login_at' => now()->subDays(3), 'last_seen_at' => now()])->save();
        $lastWeek = User::factory()->create(['name' => 'Aktif minggu lalu']);
        $lastWeek->forceFill(['last_login_at' => now()->subDays(5), 'last_seen_at' => now()->subDays(5)])->save();
        $never = User::factory()->create(['name' => 'Belum login']);
        $this->actingAs($this->admin());

        Livewire::test(ListUsers::class)
            ->filterTable('aktivitas', 'hari_ini')
            ->assertCanSeeTableRecords([$today])
            ->assertCanNotSeeTableRecords([$lastWeek, $never])
            ->filterTable('aktivitas', '7_hari')
            ->assertCanSeeTableRecords([$today, $lastWeek])
            ->assertCanNotSeeTableRecords([$never])
            ->filterTable('aktivitas', 'belum')
            ->assertCanSeeTableRecords([$never])
            ->assertCanNotSeeTableRecords([$today, $lastWeek]);

        Livewire::test(LoyaltyStats::class)->assertSee('Aktif di aplikasi hari ini');
    }

    public function test_admin_logs_a_member_out_of_every_device(): void
    {
        $member = User::factory()->create();
        $token = $member->createToken('ios-app', ['member'])->plainTextToken;
        $member->createToken('android-app', ['member']);
        $this->actingAs($this->admin(), 'web');

        Livewire::test(EditUser::class, ['record' => $member->getRouteKey()])
            ->callAction('logoutEverywhere');

        $this->assertSame(0, $member->tokens()->count());
        $this->freshRequest();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_admin_deletes_an_account_but_the_history_stays(): void
    {
        $outlet = Outlet::create(['brand_id' => Brand::create(['name' => 'Ulon', 'slug' => 'ulon', 'category' => 'culinary'])->id, 'name' => 'Ulon Ciledug']);
        $member = User::factory()->create(['name' => 'Budi', 'phone' => '6281244710921']);
        $member->createToken('ios-app', ['member']);
        app(LoyaltyService::class)->earn($member, $outlet, 250_000, 'STRUK-1');
        $voucher = Voucher::create([
            'user_id' => $member->id, 'code' => Voucher::generateCode(), 'source' => Voucher::SOURCE_CLAIM,
            'title' => 'Diskon 20rb', 'value' => 20_000, 'expires_at' => now()->addMonth(),
        ]);
        $this->actingAs($this->admin());

        Livewire::test(EditUser::class, ['record' => $member->getRouteKey()])
            ->callAction('deleteAccount')
            ->assertRedirect();

        $deleted = User::withTrashed()->find($member->id);
        $this->assertTrue($deleted->trashed());
        $this->assertSame('Akun dihapus', $deleted->name);
        $this->assertNull($deleted->phone);
        $this->assertNull($deleted->email);
        $this->assertNull($deleted->birth_date);
        $this->assertSame(0, $deleted->points_balance);
        $this->assertSame(0, $deleted->tokens()->count());
        $this->assertSame(Voucher::STATUS_EXPIRED, $voucher->fresh()->status());

        // Riwayat tetap ada: belanja + poin hangus, tercatat atas nama "Akun dihapus".
        $history = PointTransaction::where('user_id', $member->id)->oldest('id')->get();
        $this->assertCount(2, $history);
        $this->assertSame(-500, $history[1]->points);
        $this->assertSame('Akun dihapus', $history[0]->user->name);

        Livewire::test(ListUsers::class)->assertCanNotSeeTableRecords([$deleted]);
    }

    public function test_deleting_needs_permission_and_never_touches_admins(): void
    {
        $member = User::factory()->create();
        $role = Role::findOrCreate('cs', 'web');
        $role->syncPermissions(collect(['ViewAny:User', 'View:User', 'Update:User'])->map(fn ($p) => Permission::findOrCreate($p, 'web')));
        $staff = User::factory()->create();
        $staff->forceFill(['role' => User::ROLE_ADMIN])->save();
        $staff->assignRole($role);
        $this->actingAs($staff);

        Livewire::test(EditUser::class, ['record' => $member->getRouteKey()])
            ->assertActionHidden('deleteAccount');

        $role->givePermissionTo(Permission::findOrCreate('Delete:User', 'web'));
        $this->assertTrue($staff->fresh()->can('delete', $member));
        $this->assertFalse($staff->fresh()->can('delete', $this->admin()));
    }

    public function test_member_deletes_own_account_in_the_app_and_can_register_again(): void
    {
        $login = $this->loginWithOtp('081244710921');

        $this->withToken($login['token'])->deleteJson('/api/v1/me')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm');

        $this->freshRequest();
        $this->withToken($login['token'])->deleteJson('/api/v1/me', ['confirm' => true])->assertOk();

        $this->assertTrue(User::withTrashed()->find($login['user']['id'])->trashed());
        $this->freshRequest();
        $this->withToken($login['token'])->getJson('/api/v1/me')->assertUnauthorized();

        // Nomor yang sama bisa mendaftar lagi sebagai member baru (setelah jeda kirim ulang OTP).
        $this->travel(2)->minutes();
        $this->freshRequest();
        $again = $this->loginWithOtp('081244710921');
        $this->assertTrue($again['is_new']);
        $this->assertNotSame($login['user']['id'], $again['user']['id']);
    }

    public function test_cashier_cannot_delete_own_account_in_the_app(): void
    {
        $cashier = User::factory()->cashier()->create();
        $token = $cashier->createToken('ios-app', ['member', 'pos'])->plainTextToken;

        $this->withToken($token)->deleteJson('/api/v1/me', ['confirm' => true])->assertForbidden();
        $this->assertFalse($cashier->fresh()->trashed());
    }
}
