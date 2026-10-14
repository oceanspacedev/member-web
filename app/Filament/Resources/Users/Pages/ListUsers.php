<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $count = fn (string $role) => UserResource::getEloquentQuery()->where('role', $role)->count();

        return [
            'semua' => Tab::make('Semua'),
            'member' => Tab::make('Member')
                ->badge($count(User::ROLE_MEMBER))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('role', User::ROLE_MEMBER)),
            'kasir' => Tab::make('Kasir')
                ->badge($count(User::ROLE_CASHIER))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('role', User::ROLE_CASHIER)),
        ];
    }
}
