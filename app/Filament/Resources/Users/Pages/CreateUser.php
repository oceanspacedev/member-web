<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** Peran & outlet tidak mass-assignable di model, jadi diisi eksplisit di sini. */
    protected function handleRecordCreation(array $data): Model
    {
        $user = new User;
        $user->forceFill(Arr::only($data, UserResource::EDITABLE) + ['phone_verified_at' => null])->save();

        return $user;
    }
}
