<?php

namespace App\Filament\Resources\PanelUsers;

use App\Filament\Resources\PanelUsers\Pages\CreatePanelUser;
use App\Filament\Resources\PanelUsers\Pages\EditPanelUser;
use App\Filament\Resources\PanelUsers\Pages\ListPanelUsers;
use App\Filament\Resources\PanelUsers\Schemas\PanelUserForm;
use App\Filament\Resources\PanelUsers\Tables\PanelUsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Akun yang bisa masuk panel (jenis akun admin) beserta peran Shield-nya.
 */
class PanelUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'pengguna-panel';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Akses & sistem';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Pengguna panel';

    protected static ?string $modelLabel = 'pengguna panel';

    protected static ?string $pluralModelLabel = 'pengguna panel';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    /** Peran Shield disimpan lewat relasi; kolom lain diisi eksplisit (role tidak mass-assignable). */
    public const EDITABLE = ['name', 'email', 'phone', 'password', 'avatar_path', 'outlet_id'];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('role', User::ROLE_ADMIN);
    }

    /**
     * Tidak memakai UserPolicy: pemegang izin "member" tidak otomatis boleh mengubah akun admin.
     * Akun super admin hanya bisa diubah oleh super admin. Akun admin tidak dihapus (riwayat
     * penyesuaian poin merujuk ke mereka) — cabut aksesnya saja.
     */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $user = auth()->user();

        if (! in_array($action, ['viewAny', 'view', 'create', 'update'], true) || ! $user?->canInPanel('Manage:PanelUser')) {
            return Response::deny();
        }

        if ($record instanceof User && $record->hasRole(User::SUPER_ADMIN) && ! $user->isSuperAdmin()) {
            return Response::deny('Hanya super admin yang bisa mengubah akun super admin.');
        }

        return Response::allow();
    }

    public static function form(Schema $schema): Schema
    {
        return PanelUserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PanelUsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPanelUsers::route('/'),
            'create' => CreatePanelUser::route('/create'),
            'edit' => EditPanelUser::route('/{record}/edit'),
        ];
    }
}
