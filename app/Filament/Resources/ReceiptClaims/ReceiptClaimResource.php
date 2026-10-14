<?php

namespace App\Filament\Resources\ReceiptClaims;

use App\Filament\Resources\ReceiptClaims\Pages\ListReceiptClaims;
use App\Filament\Resources\ReceiptClaims\Tables\ReceiptClaimsTable;
use App\Models\ReceiptClaim;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Klaim poin dari foto struk member. Sistem memutuskan sendiri; admin hanya memeriksa struk yang
 * diserahkan sistem (decision = needs_review), dengan data yang sudah terisi dari hasil baca.
 */
class ReceiptClaimResource extends Resource
{
    protected static ?string $model = ReceiptClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCamera;

    protected static string|UnitEnum|null $navigationGroup = 'Member & poin';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Klaim struk';

    protected static ?string $modelLabel = 'klaim struk';

    protected static ?string $pluralModelLabel = 'klaim struk';

    public static function getNavigationBadge(): ?string
    {
        // Hanya yang diserahkan sistem ke admin; yang sedang dibaca sistem tidak dihitung.
        $pending = ReceiptClaim::where('status', ReceiptClaim::STATUS_PENDING)->where('decision', ReceiptClaim::DECISION_NEEDS_REVIEW)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Perlu diperiksa admin';
    }

    public static function table(Table $table): Table
    {
        return ReceiptClaimsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReceiptClaims::route('/'),
        ];
    }
}
