<?php

namespace Tests\Feature;

use App\Filament\Resources\PanelUsers\Pages\CreatePanelUser;
use App\Filament\Resources\PanelUsers\Pages\EditPanelUser;
use App\Filament\Resources\PanelUsers\Pages\ListPanelUsers;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Widgets\LoyaltyStats;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Peran & izin panel (Filament Shield), Pengguna panel, Log Viewer, dan Horizon.
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['name' => 'Pemilik', 'phone' => '6281200000003']);
        $user->forceFill(['role' => User::ROLE_ADMIN])->save();
        $user->grantSuperAdmin();

        return $user;
    }

    /** Admin panel dengan satu peran berisi izin tertentu saja. */
    private function adminWith(array $permissions, string $role = 'staf_katalog'): User
    {
        $role = Role::findOrCreate($role, 'web');
        $role->syncPermissions(collect($permissions)->map(fn (string $name) => Permission::findOrCreate($name, 'web')));

        $user = User::factory()->create();
        $user->forceFill(['role' => User::ROLE_ADMIN])->save();
        $user->assignRole($role);

        return $user;
    }

    public function test_super_admin_opens_every_menu_including_logs_and_horizon(): void
    {
        $this->actingAs($this->superAdmin());

        foreach (['/admin/brands', '/admin/users', '/admin/pengguna-panel', '/admin/shield/roles', '/log-viewer', '/horizon', '/docs/api', '/docs/api.json'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/admin')->assertSee('Log aplikasi')->assertSee('Antrean (Horizon)')->assertSee('Dokumentasi API')->assertSee('Peran &amp; izin', false);
    }

    public function test_limited_role_only_sees_what_it_is_given(): void
    {
        $this->actingAs($this->adminWith(['ViewAny:Brand', 'View:Brand', 'Update:Brand']));

        $this->get('/admin/brands')->assertOk();
        $this->get('/admin/brands/create')->assertForbidden();

        foreach (['/admin/users', '/admin/promos', '/admin/pengguna-panel', '/admin/shield/roles', '/log-viewer', '/horizon', '/docs/api', '/docs/api.json'] as $url) {
            $this->get($url)->assertForbidden();
        }

        $this->get('/admin')->assertOk()->assertDontSee('Log aplikasi')->assertDontSee('Antrean (Horizon)')->assertDontSee('Dokumentasi API');
        $this->assertFalse(LoyaltyStats::canView());
    }

    public function test_tool_permissions_open_logs_and_horizon_without_super_admin(): void
    {
        $this->actingAs($this->adminWith(['View:LogViewer', 'View:Horizon', 'View:ApiDocs'], 'developer'));

        $this->get('/log-viewer')->assertOk();
        $this->get('/docs/api')->assertOk();
        $this->get('/docs/api.json')->assertOk()->assertJsonPath('info.title', 'Ocean Space API');
        $this->getJson('/log-viewer/api/folders')->assertOk();
        $this->get('/horizon')->assertOk();
        $this->get('/admin/brands')->assertForbidden();
    }

    public function test_guests_are_sent_to_the_panel_login_and_members_are_refused(): void
    {
        $this->get('/log-viewer')->assertRedirect('/admin/login');
        $this->get('/horizon')->assertRedirect('/admin/login');
        $this->get('/docs/api')->assertRedirect('/admin/login');

        // Peran yang tertinggal di akun non-admin tidak membuka apa pun.
        $member = User::factory()->create();
        $member->grantSuperAdmin();
        $this->actingAs($member);

        $this->get('/log-viewer')->assertForbidden();
        $this->getJson('/log-viewer/api/folders')->assertForbidden();
        $this->get('/docs/api')->assertForbidden();
        $this->get('/horizon')->assertForbidden();
        $this->get('/admin')->assertForbidden();
    }

    public function test_adjust_points_needs_its_own_permission(): void
    {
        $member = User::factory()->create();
        $this->actingAs($this->adminWith(['ViewAny:User', 'View:User', 'Update:User']));

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$member])
            ->assertActionHidden(TestAction::make('adjustPoints')->table($member))
            ->assertActionHidden(TestAction::make('recordPurchase')->table($member));
    }

    public function test_super_admin_creates_a_panel_user_with_a_role_who_can_log_in_by_email(): void
    {
        $role = Role::findOrCreate('kasir_pusat', 'web');
        $this->actingAs($this->superAdmin());

        Livewire::test(CreatePanelUser::class)
            ->fillForm([
                'name' => 'Rina Marketing',
                'email' => 'rina@lifestyleclub.test',
                'phone' => '0812 3456 7890',
                'password' => 'password-rina-123',
                'password_confirmation' => 'password-rina-123',
                'roles' => [$role->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rina = User::where('email', 'rina@lifestyleclub.test')->firstOrFail();
        $this->assertSame(User::ROLE_ADMIN, $rina->role);
        $this->assertSame('6281234567890', $rina->phone);
        $this->assertTrue($rina->hasRole('kasir_pusat'));
        $this->assertTrue(Hash::check('password-rina-123', $rina->password));

        Livewire::test(CreatePanelUser::class)
            ->fillForm(['name' => 'X', 'email' => 'x@lifestyleclub.test', 'password' => 'pendek', 'password_confirmation' => 'pendek', 'roles' => [$role->id]])
            ->call('create')
            ->assertHasFormErrors(['password']);
    }

    public function test_member_registered_in_the_app_is_promoted_then_revoked(): void
    {
        $role = Role::findOrCreate('customer_service', 'web');
        $member = User::factory()->create(['name' => 'Budi Member', 'phone' => '6281299990000']);
        $member->createToken('app', ['member']);
        $this->actingAs($this->superAdmin());

        Livewire::test(ListPanelUsers::class)
            ->callAction('promote', ['user_id' => $member->id, 'roles' => [$role->id]])
            ->assertHasNoFormErrors();

        $member->refresh();
        $this->assertSame(User::ROLE_ADMIN, $member->role);
        $this->assertTrue($member->hasRole('customer_service'));

        Livewire::test(ListPanelUsers::class)
            ->callAction(TestAction::make('revoke')->table($member));

        $member->refresh();
        $this->assertSame(User::ROLE_MEMBER, $member->role);
        $this->assertCount(0, $member->roles);
        $this->assertSame(0, $member->tokens()->count());
    }

    public function test_panel_user_manager_cannot_hand_out_or_touch_super_admin(): void
    {
        $owner = $this->superAdmin();
        $member = User::factory()->create();
        $manager = $this->adminWith(['Manage:PanelUser'], 'manajer_akses');
        $this->actingAs($manager);

        // Tidak bisa membuka akun super admin, dan tidak bisa memberi peran super admin.
        $this->get("/admin/pengguna-panel/{$owner->getRouteKey()}/edit")->assertForbidden();

        Livewire::test(ListPanelUsers::class)
            ->callAction('promote', ['user_id' => $member->id, 'roles' => [Role::findByName(User::SUPER_ADMIN)->id]])
            ->assertHasFormErrors(['roles.0']);

        $this->assertSame(User::ROLE_MEMBER, $member->fresh()->role);

        Livewire::test(CreatePanelUser::class)
            ->fillForm([
                'name' => 'Penyusup',
                'email' => 'penyusup@lifestyleclub.test',
                'password' => 'password-panjang-1',
                'password_confirmation' => 'password-panjang-1',
                'roles' => [Role::findByName(User::SUPER_ADMIN)->id],
            ])
            ->call('create')
            ->assertHasFormErrors(['roles.0']);

        $this->assertFalse(User::where('email', 'penyusup@lifestyleclub.test')->exists());
    }

    public function test_own_roles_cannot_be_changed(): void
    {
        $owner = $this->superAdmin();
        $this->actingAs($owner);

        Livewire::test(EditPanelUser::class, ['record' => $owner->getRouteKey()])
            ->assertFormFieldIsDisabled('roles')
            ->assertActionHidden('revoke');
    }
}
