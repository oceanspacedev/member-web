<?php

namespace App\Filament\Resources\Promos\RelationManagers;

use App\Models\Promo;
use App\Models\VoucherCode;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Kode voucher yang diimpor dari POS outlet: stok, siapa yang memegang, dan yang sudah terpakai. */
class CodesRelationManager extends RelationManager
{
    protected static string $relationship = 'codes';

    protected static ?string $title = 'Kode voucher POS';

    protected static ?string $modelLabel = 'kode voucher';

    protected static ?string $pluralModelLabel = 'kode voucher';

    // Akses mengikuti izin mengubah promo (lihat canViewForRecord), bukan kebijakan model terpisah.
    protected static bool $shouldSkipAuthorization = true;

    // Langsung dimuat saat tabnya dibuka (dibuka dari kolom "Kode POS" di daftar promo).
    protected static bool $isLazy = false;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return $ownerRecord instanceof Promo ? (string) $ownerRecord->codes()->available()->count() : null;
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): string
    {
        return 'Kode yang masih bisa dibagikan';
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Promo && $ownerRecord->usesPosCodes() && (auth()->user()?->can('update', $ownerRecord) ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('voucher.user'))
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->weight('bold'),
                TextColumn::make('pos_title')
                    ->label('Nama di POS')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('value')
                    ->label('Potongan')
                    ->formatStateUsing(fn (?int $state) => $state ? 'Rp'.number_format($state, 0, ',', '.') : null)
                    ->placeholder('Ikut promo'),
                TextColumn::make('expires_on')
                    ->label('Kadaluarsa')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (VoucherCode $record) => VoucherCode::STATUSES[$record->status()])
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        VoucherCode::STATUSES[VoucherCode::STATUS_AVAILABLE] => 'success',
                        VoucherCode::STATUSES[VoucherCode::STATUS_ASSIGNED] => 'info',
                        VoucherCode::STATUSES[VoucherCode::STATUS_USED] => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('voucher.user.name')
                    ->label('Member')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        VoucherCode::STATUS_AVAILABLE => VoucherCode::STATUSES[VoucherCode::STATUS_AVAILABLE],
                        VoucherCode::STATUS_ASSIGNED => VoucherCode::STATUSES[VoucherCode::STATUS_ASSIGNED],
                        VoucherCode::STATUS_USED => VoucherCode::STATUSES[VoucherCode::STATUS_USED],
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        VoucherCode::STATUS_AVAILABLE => $query->available(),
                        VoucherCode::STATUS_ASSIGNED => $query->whereNotNull('voucher_id')->whereColumn('used_count', '<', 'max_uses')
                            ->whereHas('voucher', fn ($q) => $q->whereNull('used_at')),
                        VoucherCode::STATUS_USED => $query->where(fn ($q) => $q->whereColumn('used_count', '>=', 'max_uses')
                            ->orWhereHas('voucher', fn ($v) => $v->whereNotNull('used_at'))),
                        default => $query,
                    }),
            ])
            ->recordActions([
                // Kode yang sudah dipegang member tidak bisa dihapus.
                DeleteAction::make()->visible(fn (VoucherCode $record) => $record->voucher_id === null),
            ])
            ->defaultSort('id')
            // Satu batch impor biasanya puluhan kode: tampilkan sekaligus.
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50);
    }
}
