<?php

namespace App\Filament\Resources\PointTransactions;

use App\Filament\Resources\PointTransactions\Pages\ListPointTransactions;
use App\Filament\Resources\PointTransactions\Tables\PointTransactionsTable;
use App\Models\PointTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Riwayat poin hanya-baca: dicatat oleh kasir (mobile/POS) dan aksi "Sesuaikan poin".
 */
class PointTransactionResource extends Resource
{
    protected static ?string $model = PointTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Member & poin';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'transaksi poin';

    protected static ?string $pluralModelLabel = 'riwayat poin';

    protected static ?string $navigationLabel = 'Riwayat poin';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return PointTransactionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPointTransactions::route('/'),
        ];
    }
}
