<?php

namespace App\Filament\Resources\VoucherOrders\Tables;

use App\Models\VoucherOrder;
use App\Services\VoucherService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VoucherOrdersTable
{
    public static function configure(Table $table): Table
    {
        $tz = config('loyalty.timezone');

        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'voucher']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i', timezone: $tz)
                    ->sortable(),
                TextColumn::make('reference')
                    ->label('No. tagihan')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->description(fn (VoucherOrder $record) => $record->gateway_session_id),
                TextColumn::make('user.name')
                    ->label('Member')
                    ->searchable()
                    ->description(fn (VoucherOrder $record) => $record->user?->member_code),
                TextColumn::make('title')
                    ->label('Voucher')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->summarize(Sum::make()->label('Total')->money('IDR', locale: 'id', decimalPlaces: 0)),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => VoucherOrder::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        VoucherOrder::STATUS_PAID => 'success',
                        VoucherOrder::STATUS_PENDING => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (VoucherOrder $record) => $record->paid_at?->timezone($tz)->translatedFormat('d M, H:i')),
                TextColumn::make('voucher.code')
                    ->label('Kode voucher')
                    ->formatStateUsing(fn (VoucherOrder $record) => $record->voucher?->displayCode())
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(VoucherOrder::STATUSES),
                Filter::make('periode')
                    ->schema([
                        DatePicker::make('from')->label('Dari')->native(false),
                        DatePicker::make('until')->label('Sampai')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                // Bila webhook tidak sampai (mis. server sempat mati), admin bisa menanyakan status langsung ke gateway.
                Action::make('checkStatus')
                    ->label('Cek status')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (VoucherOrder $record) => $record->isPending())
                    ->action(function (VoucherOrder $record) {
                        $order = app(VoucherService::class)->refreshOrder($record, force: true);

                        Notification::make()
                            ->title('Status: '.VoucherOrder::STATUSES[$order->status])
                            ->status($order->status === VoucherOrder::STATUS_PAID ? 'success' : 'info')
                            ->send();
                    }),
            ]);
    }
}
