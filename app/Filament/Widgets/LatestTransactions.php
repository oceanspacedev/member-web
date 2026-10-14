<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PointTransactions\Tables\PointTransactionsTable;
use App\Models\PointTransaction;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Transaksi terbaru dari kasir/aplikasi — muncul di sini tanpa perlu muat ulang manual. */
class LatestTransactions extends TableWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Transaksi poin terbaru';

    public function table(Table $table): Table
    {
        return $table
            ->query(PointTransaction::query()->with(['user', 'outlet'])->latest('id')->limit(8))
            ->paginated(false)
            ->poll('30s')
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->since(),
                TextColumn::make('user.name')->label('Member'),
                TextColumn::make('type')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state) => PointTransactionsTable::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        PointTransaction::TYPE_EARN => 'success',
                        PointTransaction::TYPE_REDEEM => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('outlet.name')->label('Outlet')->placeholder('—'),
                TextColumn::make('points')->label('Poin')->weight('bold')
                    ->formatStateUsing(fn (int $state) => ($state > 0 ? '+' : '').number_format($state, 0, ',', '.'))
                    ->color(fn (int $state) => $state >= 0 ? 'success' : 'danger'),
            ]);
    }
}
