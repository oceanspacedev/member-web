<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Member & poin';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'member';

    protected static ?string $pluralModelLabel = 'member';

    protected static ?string $recordTitleAttribute = 'name';

    /** Kolom yang boleh diubah dari panel; saldo poin & tier hanya lewat transaksi. */
    public const EDITABLE = ['name', 'phone', 'email', 'birth_date', 'avatar_path', 'role', 'outlet_id'];

    /** Akun admin dikelola di Akses & sistem → Pengguna panel (izinnya terpisah). */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('role', '!=', User::ROLE_ADMIN);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'phone', 'member_code', 'email'];
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
