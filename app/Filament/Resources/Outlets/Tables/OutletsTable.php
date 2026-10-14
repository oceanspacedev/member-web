<?php

namespace App\Filament\Resources\Outlets\Tables;

use App\Models\Outlet;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class OutletsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('photo_path')
                    ->label('Foto')
                    ->disk('media')
                    ->imageWidth(72)
                    ->imageHeight(54),
                TextColumn::make('name')
                    ->label('Outlet')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Outlet $record) => $record->city),
                TextColumn::make('brand.name')
                    ->label('Brand')
                    ->sortable(),
                TextColumn::make('open_status')
                    ->label('Sekarang')
                    ->state(fn (Outlet $record) => $record->openStatus()['open'] ? 'Buka' : 'Tutup')
                    ->badge()
                    ->color(fn (string $state) => $state === 'Buka' ? 'success' : 'gray'),
                TextColumn::make('coordinates')
                    ->label('Koordinat')
                    ->state(fn (Outlet $record) => $record->latitude !== null ? 'Ada' : 'Belum diisi')
                    ->badge()
                    ->color(fn (string $state) => $state === 'Ada' ? 'success' : 'warning'),
                ToggleColumn::make('is_active')
                    ->label('Tampil'),
            ])
            ->filters([
                SelectFilter::make('brand_id')
                    ->label('Brand')
                    ->relationship('brand', 'name'),
                TernaryFilter::make('is_active')
                    ->label('Tampil di aplikasi'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
