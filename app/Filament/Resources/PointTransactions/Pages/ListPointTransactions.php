<?php

namespace App\Filament\Resources\PointTransactions\Pages;

use App\Filament\Resources\PointTransactions\PointTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListPointTransactions extends ListRecords
{
    protected static string $resource = PointTransactionResource::class;

    public function getSubheading(): ?string
    {
        return 'Dicatat otomatis dari mode kasir di aplikasi dan aksi "Sesuaikan poin" di menu Member.';
    }
}
