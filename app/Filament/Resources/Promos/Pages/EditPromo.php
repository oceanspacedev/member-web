<?php

namespace App\Filament\Resources\Promos\Pages;

use App\Filament\Resources\Promos\PromoResource;
use App\Models\Promo;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/** @property Promo $record */
class EditPromo extends EditRecord
{
    protected static string $resource = PromoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    // Promo dengan kode dari POS: form dan daftar kode tampil sebagai tab di atas, bukan tabel di bawah form.
    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return $this->record->usesPosCodes();
    }

    public function getContentTabLabel(): ?string
    {
        return 'Detail promo';
    }
}
