<?php

namespace App\Filament\Resources\PanelUsers\Pages;

use App\Filament\Resources\PanelUsers\PanelUserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreatePanelUser extends CreateRecord
{
    protected static string $resource = PanelUserResource::class;

    /** Jenis akun tidak mass-assignable di model, jadi diisi eksplisit. Peran Shield disimpan lewat relasi form. */
    protected function handleRecordCreation(array $data): Model
    {
        $user = new User;
        $user->forceFill(Arr::only($data, PanelUserResource::EDITABLE) + ['role' => User::ROLE_ADMIN])->save();

        return $user;
    }
}
