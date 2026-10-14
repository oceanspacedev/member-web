<?php

namespace App\Services\Receipts;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Membaca foto struk dengan Gemini (gambar + output JSON terstruktur). Gemini hanya membaca dan
 * menilai; keputusan memberi poin tetap di ReceiptVerifier.
 */
class GeminiReceiptReader implements ReceiptReader
{
    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeout = 90,
    ) {}

    public function read(string $imageBytes, string $mimeType, Collection $outlets): ReceiptReading
    {
        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->acceptJson()
                ->timeout($this->timeout)
                // Coba ulang gangguan sesaat (koneksi, 429, 5xx); kesalahan permintaan tidak diulang.
                ->retry(3, 1500, fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true)), throw: true)
                ->post(sprintf(self::URL, $this->model), [
                    'systemInstruction' => ['parts' => [
                        ['text' => ReceiptPrompt::SYSTEM],
                        ['text' => ReceiptPrompt::outletList($outlets)],
                    ]],
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [
                            ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($imageBytes)]],
                            ['text' => ReceiptPrompt::userText()],
                        ],
                    ]],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'responseJsonSchema' => ReceiptPrompt::schema(),
                    ],
                ]);
        } catch (ConnectionException|RequestException $e) {
            throw new ReceiptReaderUnavailable('Pembacaan struk gagal: '.$e->getMessage(), previous: $e);
        }

        $candidate = $response->json('candidates.0');
        $finish = $candidate['finishReason'] ?? null;
        if ($finish !== 'STOP') {
            $blocked = $response->json('promptFeedback.blockReason');
            throw new ReceiptReaderUnavailable('Pembacaan struk berhenti ('.($finish ?? $blocked ?? 'tanpa jawaban').').');
        }

        // Bagian "thought" adalah penalaran model; jawabannya ada di bagian teks biasa.
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (isset($part['text']) && empty($part['thought'])) {
                $data = json_decode($part['text'], true);
                if (is_array($data)) {
                    return ReceiptReading::fromArray($data);
                }
            }
        }

        throw new ReceiptReaderUnavailable('Hasil baca struk tidak bisa diurai.');
    }
}
