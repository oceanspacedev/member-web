<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Outlet;
use App\Services\Receipts\GeminiReceiptReader;
use App\Services\Receipts\ReceiptReader;
use App\Services\Receipts\ReceiptReaderUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiReceiptReaderTest extends TestCase
{
    use RefreshDatabase;

    private function reader(): GeminiReceiptReader
    {
        return new GeminiReceiptReader('test-key', 'gemini-test');
    }

    private function outlets()
    {
        $brand = Brand::create(['name' => 'J Chicken', 'slug' => 'j-chicken', 'category' => 'fastfood']);
        Outlet::create(['brand_id' => $brand->id, 'name' => 'J Chicken Ciledug', 'city' => 'Cirebon']);

        return Outlet::with('brand')->get();
    }

    public function test_it_sends_the_photo_with_the_outlet_list_and_parses_the_json_answer(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [[
            'finishReason' => 'STOP',
            'content' => ['parts' => [
                ['text' => 'menimbang...', 'thought' => true],
                ['text' => json_encode([
                    'is_receipt' => true, 'legible' => true, 'outlet_id' => 1, 'merchant_name' => 'J CHICKEN CILEDUG',
                    'receipt_number' => 'INV-1', 'purchased_at' => '2026-10-12 13:42', 'total' => 77000,
                    'capture' => 'photo_of_paper', 'tamper_suspected' => false, 'tamper_signs' => [], 'confidence' => 0.93, 'notes' => null,
                ])],
            ]],
        ]]])]);

        $reading = $this->reader()->read('jpeg-bytes', 'image/jpeg', $this->outlets());

        $this->assertSame('INV-1', $reading->receiptNumber);
        $this->assertSame(77000, $reading->total);
        $this->assertSame(1, $reading->outletId);

        Http::assertSent(function (Request $request) {
            return str_ends_with($request->url(), '/models/gemini-test:generateContent')
                && $request->header('x-goog-api-key')[0] === 'test-key'
                && $request['contents'][0]['parts'][0]['inline_data']['data'] === base64_encode('jpeg-bytes')
                && str_contains($request['systemInstruction']['parts'][1]['text'], 'id 1: J Chicken Ciledug')
                && str_contains($request['contents'][0]['parts'][1]['text'], 'Hari ini: '.now(config('loyalty.timezone'))->format('Y-m-d'))
                && $request['generationConfig']['responseMimeType'] === 'application/json';
        });
    }

    public function test_errors_and_blocked_answers_hand_the_receipt_to_an_admin(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['message' => 'API key not valid']], 400)
            ->push(['candidates' => [['finishReason' => 'SAFETY', 'content' => ['parts' => []]]]])]);

        foreach (['API key', 'SAFETY'] as $expected) {
            try {
                $this->reader()->read('x', 'image/jpeg', collect());
                $this->fail('Seharusnya gagal.');
            } catch (ReceiptReaderUnavailable $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function test_the_reader_is_only_used_when_a_key_is_configured(): void
    {
        config(['loyalty.receipts.reader' => 'gemini', 'loyalty.receipts.gemini_key' => 'k']);
        $this->assertInstanceOf(GeminiReceiptReader::class, app(ReceiptReader::class));

        config(['loyalty.receipts.gemini_key' => null]);
        $this->assertNotInstanceOf(GeminiReceiptReader::class, app(ReceiptReader::class));
    }
}
