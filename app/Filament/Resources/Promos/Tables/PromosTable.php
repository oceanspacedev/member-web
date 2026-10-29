<?php

namespace App\Filament\Resources\Promos\Tables;

use App\Filament\Resources\Promos\Pages\EditPromo;
use App\Models\Promo;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PromosTable
{
    /** Status tayang yang dilihat member di aplikasi. */
    public static function status(Promo $promo): string
    {
        return match (true) {
            ! $promo->is_active => 'Nonaktif',
            $promo->starts_at->isFuture() => 'Terjadwal',
            $promo->ends_at->endOfDay()->isPast() => 'Berakhir',
            default => 'Tayang',
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            // Yang terakhir diubah (termasuk yang baru menerima kode impor POS) tampil paling atas.
            ->defaultSort('updated_at', 'desc')
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Gambar')
                    ->disk('media')
                    ->imageWidth(96)
                    ->imageHeight(54),
                TextColumn::make('title')
                    ->label('Promo')
                    ->searchable()
                    ->weight('bold')
                    ->wrap()
                    ->description(fn (Promo $record) => $record->brand?->name ?? 'Semua outlet'),
                TextColumn::make('highlight')
                    ->label('Teks besar')
                    ->badge()
                    ->color('primary')
                    ->placeholder('—'),
                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Promo::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => $state === Promo::TYPE_INFO ? 'gray' : 'info')
                    ->description(fn (Promo $record) => $record->min_tier ? config("loyalty.tiers.{$record->min_tier}.label").' ke atas' : null),
                TextColumn::make('issued_count')
                    ->label('Voucher terbit')
                    ->formatStateUsing(fn (Promo $record) => $record->issuesVouchers()
                        ? number_format($record->issued_count, 0, ',', '.').($record->quota ? ' / '.number_format($record->quota, 0, ',', '.') : '')
                        : '—')
                    ->toggleable(),
                TextColumn::make('pos_codes')
                    ->label('Kode POS')
                    ->state(fn (Promo $record) => $record->usesPosCodes()
                        ? $record->codes()->available()->count().' tersisa · '.$record->posOutlet?->name
                        : null)
                    ->placeholder('—')
                    // Klik untuk langsung membuka daftar kodenya.
                    ->url(fn (Promo $record) => $record->usesPosCodes() ? EditPromo::getUrl(['record' => $record, 'relation' => '0']) : null)
                    ->color('primary')
                    ->icon(fn (Promo $record) => $record->usesPosCodes() ? Heroicon::OutlinedTicket : null)
                    ->tooltip(fn (Promo $record) => $record->usesPosCodes() ? 'Lihat daftar kode voucher' : null)
                    ->toggleable(),
                TextColumn::make('ends_at')
                    ->label('Periode')
                    ->formatStateUsing(fn (Promo $record) => $record->starts_at->translatedFormat('d M').' – '.$record->ends_at->translatedFormat('d M Y'))
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (Promo $record) => self::status($record))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'Tayang' => 'success',
                        'Terjadwal' => 'warning',
                        default => 'gray',
                    }),
                ToggleColumn::make('is_active')
                    ->label('Aktif'),
            ])
            ->filters([
                SelectFilter::make('brand_id')
                    ->label('Brand')
                    ->relationship('brand', 'name'),
                SelectFilter::make('type')
                    ->label('Jenis')
                    ->options(Promo::TYPES),
                SelectFilter::make('running')
                    ->label('Status')
                    ->options(['tayang' => 'Sedang tayang', 'berakhir' => 'Sudah berakhir'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'tayang' => $query->running(),
                        'berakhir' => $query->whereDate('ends_at', '<', now()),
                        default => $query,
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
