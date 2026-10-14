<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\WhatsAppLogin;
use Apriansyahrs\MekayaTheme\MekayaPlugin;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Tema Mekaya (sama dengan panel WMS): sidebar, topbar, kartu, tabel yang ringkas.
            ->plugin(
                MekayaPlugin::make()
                    ->colors(['primary' => self::charcoal()])
                    ->sidebarWidth('16rem'),
            )
            ->viteTheme('resources/css/app.css')
            // Dipanggil setelah plugin agar login kita yang dipakai. Pola sama dengan dnd-web:
            // form email + tombol "Atau masuk dengan WhatsApp" ke halaman OTP terpisah.
            ->login(Login::class)
            ->routes(fn () => Route::get('login/whatsapp', WhatsAppLogin::class)->name('auth.whatsapp-login'))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => WhatsAppLogin::isAvailable() ? view('auth.login-extra') : '')
            ->profile(isSimple: false)
            ->registration(null)
            ->passwordReset(null)
            ->brandName('Ocean Space')
            ->brandLogo(asset('media/brand/logo-mark.svg'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('media/brand/favicon.png'))
            // Peran & izin (Filament Shield), sama dengan WMS.
            ->plugin(
                FilamentShieldPlugin::make()
                    ->navigationGroup('Akses & sistem')
                    ->navigationLabel('Peran & izin')
                    ->navigationSort(2)
                    ->modelLabel('peran')
                    ->pluralModelLabel('peran & izin')
                    ->gridColumns(['default' => 1, 'lg' => 2])
                    ->sectionColumnSpan(1)
                    ->checkboxListColumns(['default' => 1, 'sm' => 2])
                    ->resourceCheckboxListColumns(['default' => 1, 'sm' => 2]),
            )
            ->navigationGroups([
                NavigationGroup::make('Katalog'),
                NavigationGroup::make('Member & poin'),
                NavigationGroup::make('Akses & sistem'),
            ])
            ->navigationItems([
                NavigationItem::make('Log aplikasi')
                    ->url('/log-viewer', shouldOpenInNewTab: true)
                    ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
                    ->group('Akses & sistem')
                    ->sort(3)
                    ->visible(fn (): bool => Gate::allows('viewLogViewer')),
                NavigationItem::make('Antrean (Horizon)')
                    ->url('/horizon', shouldOpenInNewTab: true)
                    ->icon(Heroicon::OutlinedCpuChip)
                    ->group('Akses & sistem')
                    ->sort(4)
                    ->visible(fn (): bool => Gate::allows('viewHorizon')),
                NavigationItem::make('Dokumentasi API')
                    ->url('/docs/api', shouldOpenInNewTab: true)
                    ->icon(Heroicon::OutlinedCodeBracketSquare)
                    ->group('Akses & sistem')
                    ->sort(5)
                    ->visible(fn (): bool => Gate::allows('viewApiDocs')),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * Palet monokrom aplikasi: tombol & menu aktif hitam arang (shade 600/700 = #27272A/#18181B),
     * shade terang abu hangat untuk latar sorotan. Color::hex() tidak dipakai karena memakai
     * tangga terang tetap yang membuat warna gelap jadi pucat.
     *
     * @return array<int, string>
     */
    private static function charcoal(): array
    {
        return array_map(Color::convertToOklch(...), [
            50 => '#FAFAF9',
            100 => '#F4F4F2',
            200 => '#E7E5E4',
            300 => '#D6D3D1',
            400 => '#A8A29E',
            500 => '#6B6B73',
            600 => '#27272A',
            700 => '#18181B',
            800 => '#141416',
            900 => '#0E0E10',
            950 => '#09090B',
        ]);
    }
}
