<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\Schemas\UserForm;
use App\Models\Outlet;
use App\Models\User;
use App\Services\LoyaltyService;
use App\Support\Avatar;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Yang paling baru memakai aplikasi di atas; yang belum pernah login di bawah.
            ->defaultSort('last_seen_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('tokens'))
            ->columns([
                ImageColumn::make('avatar_path')
                    ->label('')
                    ->disk('media')
                    ->circular()
                    ->defaultImageUrl(fn (User $record) => Avatar::initials($record->name)),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (User $record) => $record->phone ? '+'.$record->phone : null),
                TextColumn::make('member_code')
                    ->label('ID member')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('tier')
                    ->label('Tier')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => config("loyalty.tiers.{$state}.label", $state))
                    ->color(fn (string $state) => match ($state) {
                        'platinum' => 'primary',
                        'gold' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('points_balance')
                    ->label('Poin')
                    ->numeric(thousandsSeparator: '.')
                    ->sortable(),
                TextColumn::make('role')
                    ->label('Jenis akun')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => UserForm::ROLES[$state] ?? $state)
                    ->color(fn (string $state) => $state === User::ROLE_MEMBER ? 'gray' : 'primary'),
                TextColumn::make('last_seen_at')
                    ->label('Terakhir aktif')
                    ->since()
                    ->sortable()
                    ->placeholder('Belum pernah login')
                    ->description(fn (User $record) => User::deviceLabel($record->last_device))
                    ->tooltip(fn (User $record) => $record->last_seen_at?->timezone(config('loyalty.timezone'))->translatedFormat('d M Y, H:i')),
                TextColumn::make('tokens_count')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (int $state) => $state > 0 ? 'Login' : 'Tidak login')
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'gray')
                    ->description(fn (User $record) => $record->tokens_count > 1 ? $record->tokens_count.' perangkat' : null),
                TextColumn::make('created_at')
                    ->label('Bergabung')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('tier')
                    ->options(collect(config('loyalty.tiers'))->map(fn ($t) => $t['label'])->all()),
                SelectFilter::make('role')
                    ->label('Jenis akun')
                    ->options(UserForm::ROLES),
                SelectFilter::make('aktivitas')
                    ->label('Aktivitas')
                    ->options([
                        'hari_ini' => 'Aktif hari ini',
                        '7_hari' => 'Aktif 7 hari terakhir',
                        'login' => 'Sedang login',
                        'belum' => 'Belum pernah login',
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'hari_ini' => $query->where('last_seen_at', '>=', now(config('loyalty.timezone'))->startOfDay()->utc()),
                        '7_hari' => $query->where('last_seen_at', '>=', now()->subDays(7)),
                        'login' => $query->has('tokens'),
                        'belum' => $query->whereNull('last_login_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                self::recordPurchaseAction(),
                self::adjustPointsAction(),
                EditAction::make(),
            ]);
    }

    /**
     * Catat belanja manual — sama persis dengan scan QR di kasir: cashback sesuai tier, ikut menaikkan tier,
     * dan satu nomor struk per outlet hanya tercatat sekali. Untuk struk yang terlewat atau simulasi.
     */
    public static function recordPurchaseAction(): Action
    {
        return Action::make('recordPurchase')
            ->label('Catat belanja')
            ->icon(Heroicon::OutlinedShoppingBag)
            ->visible(fn () => auth()->user()?->canInPanel('Record:Purchase') ?? false)
            ->modalHeading(fn (User $record) => 'Catat belanja — '.($record->name ?? $record->member_code))
            ->modalDescription(fn (User $record) => config("loyalty.tiers.{$record->tier}.label").' · cashback '.config("loyalty.tiers.{$record->tier}.cashback_percent").'%. Poin masuk seperti transaksi di kasir.')
            ->modalSubmitActionLabel('Catat & tambah poin')
            ->schema([
                Select::make('outlet_id')
                    ->label('Outlet')
                    ->options(fn () => Outlet::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                TextInput::make('amount')
                    ->label('Total belanja')
                    ->prefix('Rp')
                    ->integer()
                    ->minValue(1000)
                    ->maxValue(1_000_000_000)
                    ->required()
                    ->live(onBlur: true)
                    ->helperText(fn (?string $state, User $record) => $state
                        ? '≈ '.number_format(intdiv((int) $state * config("loyalty.tiers.{$record->tier}.cashback_percent"), 100 * config('loyalty.point_value')), 0, ',', '.').' poin'
                        : null),
                TextInput::make('reference')
                    ->label('Nomor struk')
                    ->default(fn () => 'MANUAL-'.now(config('loyalty.timezone'))->format('ymd-His'))
                    ->required()
                    ->maxLength(64),
            ])
            ->action(function (User $record, array $data) {
                $transaction = app(LoyaltyService::class)->earn($record, Outlet::findOrFail($data['outlet_id']), (int) $data['amount'], $data['reference'], auth()->user());

                Notification::make()
                    ->title($transaction->wasRecentlyCreated
                        ? '+'.number_format($transaction->points, 0, ',', '.').' poin untuk '.($record->name ?? $record->member_code)
                        : 'Struk ini sudah pernah dicatat — poin tidak ditambah lagi.')
                    ->status($transaction->wasRecentlyCreated ? 'success' : 'warning')
                    ->send();
            });
    }

    public static function adjustPointsAction(): Action
    {
        return Action::make('adjustPoints')
            ->label('Sesuaikan poin')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->visible(fn () => auth()->user()?->canInPanel('Adjust:Points') ?? false)
            ->modalHeading(fn (User $record) => 'Sesuaikan poin — '.($record->name ?? $record->member_code))
            ->modalDescription(fn (User $record) => 'Saldo sekarang '.number_format($record->points_balance, 0, ',', '.').' poin. Tercatat di riwayat poin member.')
            ->schema([
                TextInput::make('points')
                    ->label('Jumlah poin')
                    ->helperText('Positif untuk menambah, negatif untuk mengurangi.')
                    ->integer()
                    ->required()
                    ->notIn([0]),
                Textarea::make('reason')
                    ->label('Alasan')
                    ->placeholder('Mis. kompensasi struk tidak tercatat')
                    ->required()
                    ->maxLength(150),
            ])
            ->action(function (User $record, array $data, Action $action) {
                try {
                    app(LoyaltyService::class)->adjust($record, (int) $data['points'], $data['reason'], auth()->user());
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Poin member diperbarui')->success()->send();
            });
    }
}
