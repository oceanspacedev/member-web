<?php

namespace App\Filament\Resources\VoucherOrders;

use App\Filament\Resources\VoucherOrders\Pages\ListVoucherOrders;
use App\Filament\Resources\VoucherOrders\Tables\VoucherOrdersTable;
use App\Models\VoucherOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Pembelian voucher dengan uang (hanya-baca): status bayar dari Xendit, untuk dicocokkan finance
 * dengan laporan settlement gateway.
 */
class VoucherOrderResource extends Resource
{
    protected static ?string $model = VoucherOrder::class;

    protected static ?string $slug = 'pembayaran-voucher';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Member & poin';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'pembayaran voucher';

    protected static ?string $pluralModelLabel = 'pembayaran voucher';

    protected static ?string $navigationLabel = 'Pembayaran voucher';

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
        return VoucherOrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVoucherOrders::route('/'),
        ];
    }
}
