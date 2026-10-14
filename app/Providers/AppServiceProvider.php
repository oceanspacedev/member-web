<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\XenditGateway;
use App\Services\Receipts\GeminiReceiptReader;
use App\Services\Receipts\NullReceiptReader;
use App\Services\Receipts\ReceiptReader;
use App\Services\WhatsApp\DemoNumberSender;
use App\Services\WhatsApp\LogWhatsAppSender;
use App\Services\WhatsApp\WagHubWhatsAppSender;
use App\Services\WhatsApp\WhatsAppSender;
use BladeUI\Icons\Factory as IconFactory;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Ikon SVG sendiri (resources/svg) untuk panel admin, mis. "lc-whatsapp" = logo WhatsApp resmi.
        $this->callAfterResolving(IconFactory::class, fn (IconFactory $icons) => $icons->add('lifestyle', [
            'path' => resource_path('svg'),
            'prefix' => 'lc',
        ]));

        // Pembaca struk otomatis; tanpa kunci API semua struk diperiksa admin seperti sebelumnya.
        $this->app->bind(ReceiptReader::class, function () {
            $cfg = config('loyalty.receipts');

            return $cfg['reader'] === 'gemini' && filled($cfg['gemini_key'])
                ? new GeminiReceiptReader($cfg['gemini_key'], $cfg['model'])
                : new NullReceiptReader;
        });

        $this->app->bind(PaymentGateway::class, function () {
            $xendit = config('loyalty.payment.xendit');

            return new XenditGateway((string) $xendit['url'], $xendit['secret_key'], $xendit['callback_token'], $xendit['return_url'], $xendit['timeout']);
        });

        $this->app->bind(WhatsAppSender::class, function ($app) {
            $config = config('loyalty.whatsapp');

            $sender = $config['driver'] === 'waghub'
                ? new WagHubWhatsAppSender((string) $config['waghub']['url'], (string) $config['waghub']['token'], $config['waghub']['timeout'])
                : new LogWhatsAppSender;

            return $app->environment('local') && $config['demo_numbers'] !== []
                ? new DemoNumberSender($sender, $config['demo_numbers'])
                : $sender;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perHour(20)->by('phone:'.preg_replace('/\D+/', '', (string) $request->input('phone'))),
        ]);

        RateLimiter::for('pos', fn (Request $request) => Limit::perMinute(60)->by('pos:'.$request->user()?->id));

        // API partner POS: per API key (diisi middleware AuthenticatePartnerKey).
        RateLimiter::for('partner', fn (Request $request) => Limit::perMinute(120)->by('partner:'.$request->attributes->get('partner_key')?->id));

        // /log-viewer: super admin atau peran dengan izin "Buka log aplikasi" (View:LogViewer).
        Gate::define('viewLogViewer', fn (?User $user = null): bool => (bool) $user?->canInPanel('View:LogViewer'));

        // /docs/api (Scramble): super admin atau peran dengan izin "Buka dokumentasi API" (View:ApiDocs).
        Gate::define('viewApiDocs', fn (?User $user = null): bool => (bool) $user?->canInPanel('View:ApiDocs'));

        // API mobile memakai token Sanctum: tampilkan sebagai Bearer agar "Try it" bisa dipakai.
        Scramble::configure()->withDocumentTransformers(
            fn (OpenApi $openApi) => $openApi->secure(SecurityScheme::http('bearer')),
        );
    }
}
