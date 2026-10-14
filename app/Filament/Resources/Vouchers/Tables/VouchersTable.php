<?php

namespace App\Filament\Resources\Vouchers\Tables;

use App\Models\Voucher;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VouchersTable
{
    public static function configure(Table $table): Table
    {
        $tz = config('loyalty.timezone');

        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'brand', 'outlet', 'cashier']))
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->formatStateUsing(fn (Voucher $record) => $record->displayCode())
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),
                TextColumn::make('user.name')
                    ->label('Member')
                    ->searchable()
                    ->description(fn (Voucher $record) => $record->user?->member_code),
                TextColumn::make('title')
                    ->label('Voucher')
                    ->wrap()
                    ->searchable()
                    ->description(fn (Voucher $record) => $record->brand_id ? ($record->brand?->name ?? 'Brand dihapus') : 'Semua outlet'),
                TextColumn::make('value')
                    ->label('Nilai')
                    ->money('IDR', locale: 'id', decimalPlaces: 0),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (Voucher $record) => $record->status())
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Voucher::STATUSES[$state])
                    ->color(fn (string $state) => match ($state) {
                        Voucher::STATUS_ACTIVE => 'success',
                        Voucher::STATUS_USED => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('source')
                    ->label('Dari')
                    ->formatStateUsing(fn (string $state) => Voucher::SOURCES[$state] ?? $state)
                    ->description(fn (Voucher $record) => match (true) {
                        $record->points_spent > 0 => number_format($record->points_spent, 0, ',', '.').' poin',
                        $record->paid_amount > 0 => 'Rp'.number_format($record->paid_amount, 0, ',', '.'),
                        default => null,
                    })
                    ->toggleable(),
                TextColumn::make('outlet.name')
                    ->label('Dipakai di')
                    ->placeholder('—')
                    ->description(fn (Voucher $record) => $record->used_at?->timezone($tz)->translatedFormat('d M Y, H:i')),
                TextColumn::make('reference')
                    ->label('No. struk')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('discount')
                    ->label('Potongan')
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->placeholder('—')
                    ->summarize(Sum::make()->label('Total potongan')->money('IDR', locale: 'id', decimalPlaces: 0)),
                TextColumn::make('expires_at')
                    ->label('Berlaku s.d.')
                    ->dateTime('d M Y', timezone: $tz)
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('cashier.name')
                    ->label('Kasir')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Voucher::STATUSES)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        Voucher::STATUS_ACTIVE => $query->active(),
                        Voucher::STATUS_USED => $query->whereNotNull('used_at'),
                        Voucher::STATUS_EXPIRED => $query->whereNull('used_at')->where('expires_at', '<=', now()),
                        default => $query,
                    }),
                SelectFilter::make('source')
                    ->label('Dari')
                    ->options(Voucher::SOURCES),
                SelectFilter::make('outlet_id')
                    ->label('Dipakai di outlet')
                    ->relationship('outlet', 'name'),
                SelectFilter::make('promo_id')
                    ->label('Promo')
                    ->relationship('promo', 'title')
                    ->searchable(),
            ]);
    }
}
