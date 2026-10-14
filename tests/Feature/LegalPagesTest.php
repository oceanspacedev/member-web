<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_legal_pages_are_public(): void
    {
        $this->get('/kebijakan-privasi')->assertOk()->assertSee('Kebijakan Privasi')->assertSee('Foto struk belanja');
        $this->get('/syarat-ketentuan')->assertOk()->assertSee('Syarat & Ketentuan')->assertSee('Poin dan level');
        $this->get('/hapus-akun')->assertOk()->assertSee('Cara menghapus akun');
    }

    public function test_terms_use_the_configured_loyalty_rules(): void
    {
        config(['loyalty.receipts.max_age_days' => 9, 'loyalty.receipts.daily_limit' => 4]);

        $this->get('/syarat-ketentuan')->assertOk()->assertSee('maksimal 9 hari')->assertSee('4 struk per hari');
    }

    public function test_contact_details_come_from_config(): void
    {
        config(['loyalty.legal.email' => 'bantuan@example.test', 'loyalty.legal.whatsapp' => '+62 812-0000-0000']);

        $this->get('/kebijakan-privasi')
            ->assertOk()
            ->assertSee('mailto:bantuan@example.test', false)
            ->assertSee('https://wa.me/628120000000', false);
    }
}
