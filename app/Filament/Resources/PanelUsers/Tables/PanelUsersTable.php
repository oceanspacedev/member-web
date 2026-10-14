<?php

namespace App\Filament\Resources\PanelUsers\Tables;

use App\Filament\Resources\PanelUsers\PanelUserResource;
use App\Filament\Resources\PanelUsers\Schemas\PanelUserForm;
use App\Models\User;
use App\Support\Avatar;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class PanelUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at')
            ->modifyQueryUsing(fn ($query) => $query->with('roles'))
            ->columns([
                ImageColumn::make('avatar_path')
                    ->label('')
                    ->disk('media')
                    ->circular()
                    ->defaultImageUrl(fn (User $record) => Avatar::initials($record->name)),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (User $record) => $record->email),
                TextColumn::make('phone')
                    ->label('WhatsApp')
                    ->formatStateUsing(fn (?string $state) => $state ? '+'.$state : null)
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('Peran')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => PanelUserForm::roleLabel($state))
                    ->color(fn (string $state) => $state === User::SUPER_ADMIN ? 'primary' : 'gray'),
                TextColumn::make('outlet.name')
                    ->label('Outlet')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->label('Peran')
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => PanelUserForm::roleLabel($record->name)),
            ])
            ->recordActions([
                EditAction::make(),
                self::revokeAction(),
            ]);
    }

    /**
     * Akun admin tidak dihapus (riwayat penyesuaian poin merujuk ke mereka):
     * jenis akunnya kembali jadi member dan semua perannya dilepas.
     */
    public static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label('Cabut akses')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record) => 'Cabut akses panel — '.$record->getFilamentName())
            ->modalDescription('Akun ini tidak bisa lagi masuk panel maupun Mode Kasir dan perlu masuk ulang di aplikasi. Data member dan poinnya tetap ada.')
            ->modalSubmitActionLabel('Cabut akses')
            ->hidden(fn (User $record) => $record->is(auth()->user()) || ! PanelUserResource::canEdit($record))
            ->action(function (User $record) {
                DB::transaction(function () use ($record) {
                    $record->syncRoles([]);
                    $record->forceFill(['role' => User::ROLE_MEMBER, 'outlet_id' => null])->save();
                    // Token aplikasi lama (termasuk akses Mode Kasir) ikut dicabut.
                    $record->tokens()->delete();
                });

                Notification::make()->title('Akses panel dicabut')->success()->send();
            });
    }
}
