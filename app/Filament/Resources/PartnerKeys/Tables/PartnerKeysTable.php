<?php

namespace App\Filament\Resources\PartnerKeys\Tables;

use App\Models\PartnerKey;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PartnerKeysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('outlet.brand'))
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (PartnerKey $record) => $record->key_prefix.'…'),
                TextColumn::make('outlet.name')
                    ->label('Outlet')
                    ->description(fn (PartnerKey $record) => $record->outlet?->brand?->name)
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (PartnerKey $record) => $record->isActive() ? 'Aktif' : 'Dicabut')
                    ->color(fn (string $state) => $state === 'Aktif' ? 'success' : 'gray'),
                TextColumn::make('last_used_at')
                    ->label('Terakhir dipakai')
                    ->since()
                    ->placeholder('Belum pernah')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->label('Status')
                    ->trueLabel('Aktif')
                    ->falseLabel('Dicabut')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNull('revoked_at'),
                        false: fn (Builder $q) => $q->whereNotNull('revoked_at'),
                    ),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Cabut')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('POS yang memakai key ini langsung tidak bisa mengecek/memakai voucher. Tidak bisa dibatalkan.')
                    ->visible(fn (PartnerKey $record) => $record->isActive())
                    ->authorize('update')
                    ->action(fn (PartnerKey $record) => $record->revoke()),
            ]);
    }
}
