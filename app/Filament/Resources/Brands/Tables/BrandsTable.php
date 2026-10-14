<?php

namespace App\Filament\Resources\Brands\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('logo_path')
                    ->label('Logo')
                    ->disk('media')
                    ->square()
                    ->defaultImageUrl(null),
                TextColumn::make('name')
                    ->label('Brand')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn ($record) => $record->legal_entity),
                TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => config("loyalty.brand_categories.{$state}", $state)),
                TextColumn::make('outlets_count')
                    ->label('Outlet')
                    ->counts('outlets')
                    ->sortable(),
                ColorColumn::make('color')
                    ->label('Warna'),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(config('loyalty.brand_categories')),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
