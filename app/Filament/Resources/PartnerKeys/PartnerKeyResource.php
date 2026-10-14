<?php

namespace App\Filament\Resources\PartnerKeys;

use App\Filament\Resources\PartnerKeys\Pages\ListPartnerKeys;
use App\Filament\Resources\PartnerKeys\Tables\PartnerKeysTable;
use App\Models\PartnerKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * API key untuk POS pihak ketiga (iSeller, Moka, Majoo, Olsera, ...) yang memvalidasi voucher lewat API partner.
 */
class PartnerKeyResource extends Resource
{
    protected static ?string $model = PartnerKey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Akses & sistem';

    protected static ?string $navigationLabel = 'API partner POS';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'API key';

    protected static ?string $pluralModelLabel = 'API partner POS';

    public static function table(Table $table): Table
    {
        return PartnerKeysTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerKeys::route('/'),
        ];
    }
}
