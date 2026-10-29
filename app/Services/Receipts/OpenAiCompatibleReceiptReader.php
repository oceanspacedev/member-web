<?php

namespace App\Services\Receipts;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Membaca foto struk lewat API chat completions yang kompatibel dengan OpenAI (OpenAI sendiri atau
 * router/proxy dengan format yang sama). Model hanya membaca dan menilai; keputusan memberi poin
 * tetap di ReceiptVerifier.
 */
class OpenAiCompatibleReceiptReader implements ReceiptReader
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout = 90,
    ) {}

    public function read(string $imageBytes, string $mimeType, Collection $outlets): ReceiptReading
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->timeout)
                // Coba ulang gangguan sesaat (koneksi, 429, 5xx); kesalahan permintaan tidak diulang.
                ->retry(3, 1500, fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true)), throw: true)
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', [
                    'model' => $this->model,
                    // Sebagian router menjawab dengan stream bila tidak diminta tegas.
                    'stream' => false,
                    'messages' => [
                        ['role' => 'system', 'content' => ReceiptPrompt::SYSTEM."\n\n".ReceiptPrompt::outletList($outlets)],
                        ['role' => 'user', 'content' => [
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mimeType.';base64,'.base64_encode($imageBytes)]],
                            ['type' => 'text', 'text' => ReceiptPrompt::userText()],
                        ]],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => ['name' => 'receipt_reading', 'strict' => true, 'schema' => ReceiptPrompt::schema()],
                    ],
                ]);
        } catch (ConnectionException|RequestException $e) {
            throw new ReceiptReaderUnavailable('Pembacaan struk gagal: '.$e->getMessage(), previous: $e);
        }

        $choice = $response->json('choices.0');
        if (! is_array($choice)) {
            throw new ReceiptReaderUnavailable('Pembacaan struk tidak memberi jawaban.');
        }

        // Jawaban terpotong atau disaring tidak bisa dipercaya meski sebagian JSON-nya terbaca.
        $finish = $choice['finish_reason'] ?? null;
        if (in_array($finish, ['length', 'content_filter'], true)) {
            throw new ReceiptReaderUnavailable("Pembacaan struk berhenti ({$finish}).");
        }

        $content = $choice['message']['content'] ?? null;
        $data = self::decode($content);
        if ($data === null) {
            $text = is_string($content) ? Str::limit(trim($content), 160) : 'tanpa teks';
            throw new ReceiptReaderUnavailable("Hasil baca struk tidak bisa diurai: {$text}");
        }

        return ReceiptReading::fromArray($data);
    }

    /**
     * Isi jawaban sebagai array. Sebagian model membungkus JSON dengan ```json atau menambah kalimat
     * walau diminta JSON murni, dan sebagian mengirim isi sebagai daftar bagian teks.
     *
     * @return array<string, mixed>|null
     */
    private static function decode(mixed $content): ?array
    {
        if (is_array($content)) {
            $content = implode('', array_map(fn ($part) => is_array($part) ? (string) ($part['text'] ?? '') : '', $content));
        }
        if (! is_string($content)) {
            return null;
        }

        $text = trim($content);
        $data = json_decode($text, true);

        // Bukan JSON murni: ambil objek dari kurung kurawal pertama sampai terakhir.
        if (! is_array($data)) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            $data = $start !== false && $end > $start ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        }

        return is_array($data) && array_key_exists('is_receipt', $data) ? $data : null;
    }
}
