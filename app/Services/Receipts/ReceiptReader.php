<?php

namespace App\Services\Receipts;

use App\Models\Outlet;
use Illuminate\Support\Collection;

/**
 * Membaca foto struk menjadi data terstruktur, termasuk penilaian apakah foto tampak diedit.
 */
interface ReceiptReader
{
    /**
     * @param  Collection<int, Outlet>  $outlets  outlet aktif, untuk dicocokkan dengan nama/alamat di struk
     *
     * @throws ReceiptReaderUnavailable bila pembaca tidak dikonfigurasi atau layanan gagal
     */
    public function read(string $imageBytes, string $mimeType, Collection $outlets): ReceiptReading;
}
