<?php

namespace App\Services\Receipts;

use Illuminate\Support\Collection;

/** Dipakai bila pembacaan otomatis dimatikan: semua struk masuk antrean admin seperti sebelumnya. */
class NullReceiptReader implements ReceiptReader
{
    public function read(string $imageBytes, string $mimeType, Collection $outlets): ReceiptReading
    {
        throw new ReceiptReaderUnavailable('Pembacaan struk otomatis tidak aktif.');
    }
}
