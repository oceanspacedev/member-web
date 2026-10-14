<?php

namespace App\Filament\Resources\ReceiptClaims\Tables;

use App\Models\Outlet;
use App\Models\ReceiptClaim;
use App\Services\ReceiptService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReceiptClaimsTable
{
    /** Alasan penolakan yang sering dipakai; tetap bisa diisi bebas. */
    private const REJECT_REASONS = [
        'Foto struk tidak jelas/terpotong. Kirim ulang foto yang lebih jelas.',
        'Struk dari outlet lain, bukan outlet yang dipilih.',
        'Struk sudah melewati batas waktu klaim.',
        'Struk bukan dari outlet Ocean Space.',
        'Struk sudah pernah diklaim.',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'outlet.brand', 'transaction', 'reviewer']))
            ->columns([
                ImageColumn::make('photo')
                    ->label('Struk')
                    ->state(fn (ReceiptClaim $record) => route('receipt-photos.show', $record))
                    ->imageHeight(56)
                    ->url(fn (ReceiptClaim $record) => route('receipt-photos.show', $record), shouldOpenInNewTab: true),
                TextColumn::make('user.name')
                    ->label('Member')
                    ->placeholder('—')
                    ->description(fn (ReceiptClaim $record) => $record->user?->member_code)
                    ->searchable(['name', 'member_code', 'phone']),
                TextColumn::make('outlet.name')
                    ->label('Outlet')
                    ->placeholder('Belum dikenali')
                    ->description(fn (ReceiptClaim $record) => $record->outlet?->brand?->name ?? ($record->reading['merchant_name'] ?? null)),
                TextColumn::make('receipt_number')
                    ->label('No. struk')
                    ->placeholder('—')
                    ->searchable()
                    ->fontFamily('mono')
                    ->description(fn (ReceiptClaim $record) => $record->purchased_at?->translatedFormat('d M Y')),
                TextColumn::make('amount')
                    ->label('Total')
                    ->placeholder('—')
                    ->money('IDR', decimalPlaces: 0),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, ReceiptClaim $record) => $record->isChecking() ? 'Sedang dibaca sistem' : (ReceiptClaim::STATUSES[$state] ?? $state))
                    ->color(fn (string $state) => match ($state) {
                        ReceiptClaim::STATUS_APPROVED => 'success',
                        ReceiptClaim::STATUS_REJECTED => 'danger',
                        default => 'warning',
                    })
                    ->description(fn (ReceiptClaim $record) => match ($record->status) {
                        ReceiptClaim::STATUS_APPROVED => '+'.number_format((int) $record->transaction?->points, 0, ',', '.').' poin',
                        ReceiptClaim::STATUS_REJECTED => $record->reject_reason,
                        default => $record->flags ? implode(' ', $record->flags) : null,
                    })
                    ->wrap(),
                TextColumn::make('reviewer.name')
                    ->label('Diputuskan oleh')
                    ->state(fn (ReceiptClaim $record) => match (true) {
                        $record->isPending() => null,
                        $record->reviewed_by !== null => $record->reviewer?->name ?? 'Admin',
                        default => 'Sistem',
                    })
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Dikirim')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                // Default: hanya struk yang diserahkan sistem ke admin.
                SelectFilter::make('decision')
                    ->label('Keputusan sistem')
                    ->options(ReceiptClaim::DECISIONS)
                    ->default(ReceiptClaim::DECISION_NEEDS_REVIEW),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ReceiptClaim::STATUSES)
                    ->default(ReceiptClaim::STATUS_PENDING),
                SelectFilter::make('outlet')
                    ->label('Outlet')
                    ->relationship('outlet', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                self::approveAction(),
                self::rejectAction(),
            ]);
    }

    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Setujui')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (ReceiptClaim $record) => $record->isPending() && ! $record->isChecking())
            ->authorize('update')
            ->modalHeading('Periksa & setujui struk')
            ->modalWidth('3xl')
            ->modalDescription('Data sudah diisi dari hasil baca sistem. Cocokkan dengan foto, perbaiki bila perlu. Nomor struk dicek agar satu struk hanya mendapat poin sekali.')
            ->modalSubmitActionLabel('Setujui & tambah poin')
            ->fillForm(fn (ReceiptClaim $record) => [
                'outlet_id' => $record->outlet_id,
                'receipt_number' => $record->receipt_number,
                'amount' => $record->amount,
                'purchased_at' => substr((string) ($record->reading['purchased_at'] ?? ''), 0, 10) ?: $record->created_at->timezone(config('loyalty.timezone'))->toDateString(),
            ])
            ->schema(fn (ReceiptClaim $record) => [
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    ImageEntry::make('photo')
                        ->hiddenLabel()
                        ->state(route('receipt-photos.show', $record))
                        ->imageHeight(420)
                        ->url(route('receipt-photos.show', $record), shouldOpenInNewTab: true),
                    Grid::make(1)->schema([
                        TextEntry::make('member')->label('Member')->state(($record->user?->name ?? '—').' · '.$record->user?->member_code),
                        TextEntry::make('flags')
                            ->label('Kenapa perlu diperiksa')
                            ->state($record->flags ?: ['—'])
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->color('warning'),
                        Select::make('outlet_id')
                            ->label('Outlet')
                            ->options(fn () => Outlet::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        TextEntry::make('sent')->label('Dikirim')->state($record->created_at->timezone(config('loyalty.timezone'))->translatedFormat('d M Y H:i')),
                        TextInput::make('receipt_number')
                            ->label('Nomor struk')
                            ->required()
                            ->maxLength(64),
                        DatePicker::make('purchased_at')
                            ->label('Tanggal belanja')
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->maxDate(now(config('loyalty.timezone')))
                            ->required(),
                        TextInput::make('amount')
                            ->label('Total belanja')
                            ->helperText(fn (?string $state) => $state && $record->user
                                ? '≈ '.number_format(intdiv((int) $state * config("loyalty.tiers.{$record->user->tier}.cashback_percent"), 100 * config('loyalty.point_value')), 0, ',', '.').' poin'
                                : null)
                            ->live(onBlur: true)
                            ->prefix('Rp')
                            ->integer()
                            ->minValue(1000)
                            ->maxValue(100_000_000)
                            ->required(),
                    ]),
                ]),
            ])
            ->action(function (ReceiptClaim $record, array $data) {
                $claim = app(ReceiptService::class)->approve($record, auth()->user(), $data['receipt_number'], (int) $data['amount'], $data['purchased_at'], (int) $data['outlet_id']);

                Notification::make()
                    ->title('+'.number_format($claim->transaction->points, 0, ',', '.').' poin untuk '.($claim->user?->name ?? $claim->user?->member_code))
                    ->success()
                    ->send();
            });
    }

    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Tolak')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (ReceiptClaim $record) => $record->isPending() && ! $record->isChecking())
            ->authorize('update')
            ->modalHeading('Tolak klaim struk')
            ->modalDescription('Alasan dikirim ke member lewat WhatsApp dan tampil di aplikasi.')
            ->schema([
                Select::make('reason')
                    ->label('Alasan')
                    ->options(array_combine(self::REJECT_REASONS, self::REJECT_REASONS))
                    ->searchable()
                    ->createOptionForm([TextInput::make('reason')->label('Alasan lain')->required()->maxLength(200)])
                    ->createOptionUsing(fn (array $data) => $data['reason'])
                    ->required(),
            ])
            ->action(function (ReceiptClaim $record, array $data) {
                app(ReceiptService::class)->reject($record, auth()->user(), $data['reason']);

                Notification::make()->title('Klaim struk ditolak')->success()->send();
            });
    }
}
