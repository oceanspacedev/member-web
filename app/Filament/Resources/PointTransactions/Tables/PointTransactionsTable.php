<?php

namespace App\Filament\Resources\PointTransactions\Tables;

use App\Models\PointTransaction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PointTransactionsTable
{
    public const TYPES = [
        PointTransaction::TYPE_EARN => 'Belanja',
        PointTransaction::TYPE_REDEEM => 'Tukar poin',
        PointTransaction::TYPE_ADJUST => 'Penyesuaian',
        PointTransaction::TYPE_VOUCHER => 'Beli voucher',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'outlet', 'cashier']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i', timezone: config('loyalty.timezone'))
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Member')
                    ->searchable()
                    ->description(fn (PointTransaction $record) => $record->user?->member_code),
                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        PointTransaction::TYPE_EARN => 'success',
                        PointTransaction::TYPE_REDEEM => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('outlet.name')
                    ->label('Outlet')
                    ->placeholder('—'),
                TextColumn::make('amount')
                    ->label('Nilai')
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->placeholder('—'),
                TextColumn::make('points')
                    ->label('Poin')
                    ->formatStateUsing(fn (int $state) => ($state > 0 ? '+' : '').number_format($state, 0, ',', '.'))
                    ->color(fn (int $state) => $state >= 0 ? 'success' : 'danger')
                    ->weight('bold'),
                TextColumn::make('balance_after')
                    ->label('Saldo')
                    ->numeric(thousandsSeparator: '.'),
                TextColumn::make('reference')
                    ->label('No. struk')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('cashier.name')
                    ->label('Dicatat oleh')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('description')
                    ->label('Keterangan')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Jenis')
                    ->options(self::TYPES),
                SelectFilter::make('outlet_id')
                    ->label('Outlet')
                    ->relationship('outlet', 'name'),
                Filter::make('periode')
                    ->schema([
                        DatePicker::make('from')->label('Dari')->native(false),
                        DatePicker::make('until')->label('Sampai')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ]);
    }
}
