<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    private const PLAY = 'https://play.google.com/store/apps/details?id=id.co.oceanspace.member';

    private const APPLE = 'https://apps.apple.com/id/app/ocean-space/id1234567890';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private const ANDROID = 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

    private const DESKTOP = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    public function test_landing_page_is_public_and_describes_the_app(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Satu kartu member untuk', false)
            ->assertSee('Poin dari foto struk')
            ->assertSee('Mulai dalam tiga langkah')
            ->assertSee('Pertanyaan yang sering diajukan')
            ->assertSee('<link rel="canonical" href="'.url('/').'">', false)
            ->assertSee('property="og:image"', false)
            ->assertSee('"@type":"FAQPage"', false)
            ->assertSee(route('legal.privacy'), false)
            ->assertSee(route('legal.terms'), false)
            ->assertSee(route('legal.delete-account'), false);
    }

    public function test_store_buttons_say_coming_soon_until_links_are_configured(): void
    {
        config(['loyalty.stores' => ['android' => null, 'ios' => '']]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Segera hadir di')
            ->assertDontSee('data-store=', false);
    }

    public function test_store_buttons_link_to_the_configured_stores(): void
    {
        config(['loyalty.stores' => ['android' => self::PLAY, 'ios' => self::APPLE]]);

        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.self::PLAY.'"', false)
            ->assertSee('href="'.self::APPLE.'"', false)
            ->assertSee('Dapatkan di')
            ->assertSee('Unduh di')
            ->assertDontSee('Segera hadir di');
    }

    public function test_store_links_that_are_not_https_are_ignored(): void
    {
        config(['loyalty.stores' => ['android' => 'javascript:alert(1)', 'ios' => 'http://apps.apple.com/app/id1']]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('javascript:alert', false)
            ->assertDontSee('http://apps.apple.com', false)
            ->assertSee('Segera hadir di');
    }

    public function test_landing_uses_brands_outlets_and_loyalty_rules_from_the_app(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->get('/')
            ->assertOk()
            ->assertSee('10 brand, satu kartu member')
            ->assertSee('Araichi Ramen')
            ->assertSee('Toko Mas An An')
            ->assertSee('Kuliner &amp; Resto', false)
            ->assertSee('Platinum')
            ->assertSee('Cashback poin 8%')
            // Rp100.000 × 8% ÷ Rp10 per poin
            ->assertSee('800 poin');
    }

    public function test_landing_follows_changes_to_the_loyalty_config(): void
    {
        config(['loyalty.point_value' => 20, 'loyalty.receipts.max_age_days' => 3, 'loyalty.receipts.daily_limit' => 2]);

        $this->get('/')
            ->assertOk()
            ->assertSee('1 poin bernilai Rp20')
            ->assertSee('maksimal 3 hari')
            ->assertSee('2 struk per hari');
    }

    public function test_download_link_sends_each_platform_to_its_store(): void
    {
        config(['loyalty.stores' => ['android' => self::PLAY, 'ios' => self::APPLE]]);

        $this->withHeader('User-Agent', self::IPHONE)->get('/unduh')->assertRedirect(self::APPLE);
        $this->withHeader('User-Agent', self::ANDROID)->get('/unduh')->assertRedirect(self::PLAY);
        $this->withHeader('User-Agent', self::DESKTOP)->get('/unduh')->assertRedirect(route('landing').'#unduh');
    }

    public function test_download_link_falls_back_to_the_landing_page_when_the_store_is_not_live(): void
    {
        config(['loyalty.stores' => ['android' => null, 'ios' => self::APPLE]]);

        $this->withHeader('User-Agent', self::ANDROID)->get('/unduh')->assertRedirect(route('landing').'#unduh');
        $this->withHeader('User-Agent', self::IPHONE)->get('/unduh')->assertRedirect(self::APPLE);
    }

    public function test_download_redirect_is_never_cached(): void
    {
        $this->withHeader('User-Agent', self::DESKTOP)->get('/unduh')->assertHeader('Cache-Control', 'no-store, private');
    }
}
