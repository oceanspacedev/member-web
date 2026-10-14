<?php

namespace App\Filament\Resources\PanelUsers\Pages;

use App\Filament\Resources\PanelUsers\PanelUserResource;
use App\Filament\Resources\PanelUsers\Tables\PanelUsersTable;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditPanelUser extends EditRecord
{
    protected static string $resource = PanelUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PanelUsersTable::revokeAction()->after(fn () => $this->redirect(PanelUserResource::getUrl())),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->forceFill(Arr::only($data, PanelUserResource::EDITABLE))->save();

        return $record;
    }
}
