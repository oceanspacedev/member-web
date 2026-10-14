<?php

namespace App\Filament\Resources\ReceiptClaims\Pages;

use App\Filament\Resources\ReceiptClaims\ReceiptClaimResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListReceiptClaims extends ListRecords
{
    protected static string $resource = ReceiptClaimResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Struk belanja yang difoto member dari outlet dengan POS pihak ketiga. Saat menyetujui, isi nomor struk, tanggal, dan total dari foto.';
    }
}
