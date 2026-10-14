<?php

namespace App\Filament\Resources\PanelUsers\Pages;

use App\Filament\Resources\PanelUsers\PanelUserResource;
use App\Filament\Resources\PanelUsers\Schemas\PanelUserForm;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class ListPanelUsers extends ListRecords
{
    protected static string $resource = PanelUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->promoteAction(),
            CreateAction::make()->label('Buat akun admin'),
        ];
    }

    /** Member/kasir yang sudah terdaftar di aplikasi dijadikan admin — login lewat OTP WhatsApp-nya. */
    protected function promoteAction(): Action
    {
        return Action::make('promote')
            ->label('Jadikan admin dari member')
            ->icon(Heroicon::OutlinedArrowUpCircle)
            ->color('gray')
            ->visible(fn () => PanelUserResource::canCreate())
            ->modalDescription('Akun ini bisa masuk panel dengan OTP ke nomor WhatsApp-nya. Email + password bisa diisi lewat menu ubah.')
            ->modalSubmitActionLabel('Jadikan admin')
            ->schema([
                Select::make('user_id')
                    ->label('Member atau kasir')
                    ->placeholder('Cari nama, nomor, atau ID member')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => self::promotable($search)
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (User $user) => [$user->getKey() => self::label($user)])
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => ($user = User::find($value)) ? self::label($user) : null)
                    ->required(),
                Select::make('roles')
                    ->label('Peran')
                    ->multiple()
                    ->options(fn () => PanelUserForm::assignableRoles(Role::query())
                        ->pluck('name', 'id')
                        ->map(PanelUserForm::roleLabel(...))
                        ->all())
                    ->required()
                    ->nestedRecursiveRule(PanelUserForm::assignableRoleRule(...)),
            ])
            ->action(function (array $data) {
                $user = self::promotable()->findOrFail($data['user_id']);
                $roles = PanelUserForm::assignableRoles(Role::query())->whereKey($data['roles'])->get();

                DB::transaction(function () use ($user, $roles) {
                    $user->forceFill(['role' => User::ROLE_ADMIN])->save();
                    $user->syncRoles($roles);
                });

                Notification::make()->title($user->getFilamentName().' sekarang admin panel')->success()->send();
            });
    }

    private static function promotable(?string $search = null): Builder
    {
        return User::query()
            ->where('role', '!=', User::ROLE_ADMIN)
            ->when(filled($search), function (Builder $query) use ($search) {
                $digits = ltrim(preg_replace('/\D+/', '', $search), '0');

                $query->where(fn (Builder $q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('member_code', 'like', "%{$search}%")
                    ->when($digits !== '', fn (Builder $q) => $q->orWhere('phone', 'like', "%{$digits}%")));
            })
            ->orderBy('name');
    }

    private static function label(User $user): string
    {
        return collect([$user->name, $user->phone ? '+'.$user->phone : null, $user->member_code])->filter()->implode(' · ');
    }
}
