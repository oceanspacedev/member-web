<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Outlet;
use App\Services\Receipts\GeminiReceiptReader;
use App\Services\Receipts\NullReceiptReader;
use App\Services\Receipts\OpenAiCompatibleReceiptReader;
use App\Services\Receipts\ReceiptPrompt;
use App\Services\Receipts\ReceiptReader;
use App\Services\Receipts\ReceiptReaderUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiCompatibleReceiptReaderTest extends TestCase
{
    use RefreshDatabase;

    private const READING = [
        'is_receipt' => true, 'legible' => true, 'outlet_id' => 1, 'merchant_name' => 'J CHICKEN CILEDUG',
        'receipt_number' => 'INV-1', 'purchased_at' => '2026-10-12 13:42', 'total' => 77000,
        'capture' => 'photo_of_paper', 'tamper_suspected' => false, 'tamper_signs' => [], 'confidence' => 0.93, 'notes' => null,
    ];

    private function reader(): OpenAiCompatibleReceiptReader
    {
        return new OpenAiCompatibleReceiptReader('test-key', 'https://router.test/v1/', 'vision-test');
    }

    private function outlets()
    {
        $brand = Brand::create(['name' => 'J Chicken', 'slug' => 'j-chicken', 'category' => 'fastfood']);
        Outlet::create(['brand_id' => $brand->id, 'name' => 'J Chicken Ciledug', 'city' => 'Cirebon']);

        return Outlet::with('brand')->get();
    }

    private static function answer(string $content, string $finish = 'stop'): array
    {
        return ['choices' => [['index' => 0, 'finish_reason' => $finish, 'message' => ['role' => 'assistant', 'content' => $content]]]];
    }

    public function test_it_sends_the_photo_with_the_outlet_list_and_parses_the_json_answer(): void
    {
        Http::fake(['router.test/*' => Http::response(self::answer(json_encode(self::READING)))]);

        $reading = $this->reader()->read('jpeg-bytes', 'image/jpeg', $this->outlets());

        $this->assertSame('INV-1', $reading->receiptNumber);
        $this->assertSame(77000, $reading->total);
        $this->assertSame(1, $reading->outletId);

        Http::assertSent(function (Request $request) {
            $user = $request['messages'][1]['content'];

            return $request->url() === 'https://router.test/v1/chat/completions'
                && $request->header('Authorization')[0] === 'Bearer test-key'
                && $request['model'] === 'vision-test'
                && $request['stream'] === false
                && str_contains($request['messages'][0]['content'], 'id 1: J Chicken Ciledug')
                && $user[0]['image_url']['url'] === 'data:image/jpeg;base64,'.base64_encode('jpeg-bytes')
                && str_contains($user[1]['text'], 'Hari ini: '.now(config('loyalty.timezone'))->format('Y-m-d'))
                && $request['response_format']['type'] === 'json_schema';
        });
    }

    public function test_each_brand_receipt_guide_is_sent_once_so_real_outlet_receipts_can_be_told_apart(): void
    {
        $brand = Brand::create(['name' => 'J Chicken', 'slug' => 'j-chicken', 'category' => 'fastfood', 'receipt_guide' => 'Struk berkop "J CHICKEN", nomor struk = "No. Bill".']);
        Outlet::create(['brand_id' => $brand->id, 'name' => 'J Chicken Ciledug']);
        Outlet::create(['brand_id' => $brand->id, 'name' => 'J Chicken Tuparev']);
        $plain = Brand::create(['name' => 'Tanpa Ciri', 'slug' => 'tanpa-ciri', 'category' => 'retail']);
        Outlet::create(['brand_id' => $plain->id, 'name' => 'Tanpa Ciri Pusat']);

        $text = ReceiptPrompt::outletList(Outlet::with('brand')->get());

        $this->assertStringContainsString("Ciri struk per brand:\n### J Chicken\nStruk berkop \"J CHICKEN\"", $text);
        $this->assertSame(1, substr_count($text, '### J Chicken'));
        $this->assertStringNotContainsString('### Tanpa Ciri', $text);
        $this->assertContains('merchant_match', ReceiptPrompt::schema()['required']);
        $this->assertContains('transaction_type', ReceiptPrompt::schema()['required']);
    }

    public function test_json_wrapped_in_a_code_block_or_sentences_is_still_read(): void
    {
        $json = json_encode(self::READING, JSON_PRETTY_PRINT);
        Http::fake(['*' => Http::sequence()
            ->push(self::answer("```json\n{$json}\n```"))
            ->push(self::answer("Berikut hasilnya:\n```json\n{$json}\n```\nSemoga membantu."))]);

        $this->assertSame('INV-1', $this->reader()->read('x', 'image/jpeg', collect())->receiptNumber);
        $this->assertSame(77000, $this->reader()->read('x', 'image/jpeg', collect())->total);
    }

    public function test_errors_and_unusable_answers_hand_the_receipt_to_an_admin(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['message' => 'Invalid API key']], 401)
            ->push(self::answer('Model ini sudah tidak tersedia.'))
            ->push(self::answer('{"is_receipt": true, "legible"', 'length'))]);

        foreach (['Invalid API key', 'tidak bisa diurai: Model ini sudah tidak tersedia.', 'length'] as $expected) {
            try {
                $this->reader()->read('x', 'image/jpeg', collect());
                $this->fail('Seharusnya gagal.');
            } catch (ReceiptReaderUnavailable $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function test_gemini_is_preferred_and_the_router_is_used_when_gemini_has_no_key(): void
    {
        $router = ['url' => 'https://router.test/v1', 'key' => 'k', 'model' => 'vision-test'];

        // Bawaan (auto) dan "gemini": Gemini bila kuncinya ada, selain itu router.
        foreach (['auto', 'gemini'] as $mode) {
            config(['loyalty.receipts.reader' => $mode, 'loyalty.receipts.gemini_key' => 'g', 'loyalty.receipts.openai_compatible' => $router]);
            $this->assertInstanceOf(GeminiReceiptReader::class, app(ReceiptReader::class));

            config(['loyalty.receipts.gemini_key' => null]);
            $this->assertInstanceOf(OpenAiCompatibleReceiptReader::class, app(ReceiptReader::class));
        }

        // "openai" memaksa router; "none" mematikan pembacaan otomatis.
        config(['loyalty.receipts.reader' => 'openai', 'loyalty.receipts.gemini_key' => 'g']);
        $this->assertInstanceOf(OpenAiCompatibleReceiptReader::class, app(ReceiptReader::class));

        config(['loyalty.receipts.reader' => 'none']);
        $this->assertInstanceOf(NullReceiptReader::class, app(ReceiptReader::class));

        // Router tanpa model dianggap belum dikonfigurasi.
        config(['loyalty.receipts.reader' => 'auto', 'loyalty.receipts.gemini_key' => null, 'loyalty.receipts.openai_compatible.model' => null]);
        $this->assertInstanceOf(NullReceiptReader::class, app(ReceiptReader::class));
    }
}
