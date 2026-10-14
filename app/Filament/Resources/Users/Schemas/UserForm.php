<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use App\Support\Phone;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class UserForm
{
    /** Jenis akun yang dikelola di menu Member. Admin panel ada di Akses & sistem → Pengguna panel. */
    public const ROLES = [
        User::ROLE_MEMBER => 'Member',
        User::ROLE_CASHIER => 'Kasir',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    Section::make('Data member')
                        ->columnSpan(2)
                        ->columns(2)
                        ->schema([
                            TextInput::make('name')
                                ->label('Nama')
                                ->required()
                                ->maxLength(100),
                            self::phoneInput()
                                ->required()
                                ->helperText('Dipakai untuk login OTP di aplikasi.'),
                            TextInput::make('email')
                                ->email()
                                ->maxLength(150)
                                ->unique(ignoreRecord: true),
                            DatePicker::make('birth_date')
                                ->label('Tanggal lahir')
                                ->native(false)
                                ->displayFormat('d M Y')
                                ->maxDate(now()),
                        ]),
                    Section::make('Foto profil')
                        ->columnSpan(1)
                        ->schema([self::avatarUpload()]),
                ]),
            Section::make('Akses')
                ->description('Untuk memberi akses panel admin, buka Akses & sistem → Pengguna panel.')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Select::make('role')
                        ->label('Jenis akun')
                        ->options(self::ROLES)
                        ->default(User::ROLE_MEMBER)
                        ->required()
                        ->native(false)
                        ->live(),
                    Select::make('outlet_id')
                        ->label('Outlet kasir')
                        ->relationship('outlet', 'name')
                        ->searchable()
                        ->preload()
                        ->helperText('Poin dari mode kasir dicatat ke outlet ini.')
                        ->visible(fn (Get $get) => $get('role') === User::ROLE_CASHIER)
                        ->required(fn (Get $get) => $get('role') === User::ROLE_CASHIER),
                ]),
            Section::make('Aktivitas aplikasi')
                ->description('Kapan member login dan memakai aplikasi, serta perangkat yang sedang login.')
                ->columnSpanFull()
                ->columns(3)
                ->hiddenOn('create')
                ->schema([
                    TextEntry::make('last_login_at')
                        ->label('Login terakhir')
                        ->dateTime('d M Y, H:i', timezone: config('loyalty.timezone'))
                        ->placeholder('Belum pernah login'),
                    TextEntry::make('last_seen_at')
                        ->label('Terakhir aktif')
                        ->since()
                        ->placeholder('—'),
                    TextEntry::make('last_device')
                        ->label('Perangkat terakhir')
                        ->formatStateUsing(fn (?string $state) => User::deviceLabel($state))
                        ->placeholder('—'),
                    TextEntry::make('sessions')
                        ->label('Sedang login di')
                        ->columnSpanFull()
                        ->state(fn (?User $record) => $record?->tokens()->latest('id')->get()
                            ->map(fn ($token) => User::deviceLabel($token->name)
                                .' · masuk '.$token->created_at->timezone(config('loyalty.timezone'))->translatedFormat('d M Y, H:i')
                                .($token->last_used_at ? ' · terakhir dipakai '.$token->last_used_at->diffForHumans() : ''))
                            ->all())
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('Tidak sedang login di perangkat mana pun'),
                ]),
            Section::make('Loyalti')
                ->description('Saldo poin hanya berubah lewat transaksi (kasir) atau aksi "Sesuaikan poin".')
                ->columnSpanFull()
                ->columns(3)
                ->hiddenOn('create')
                ->schema([
                    TextInput::make('member_code')->label('ID member')->disabled()->dehydrated(false),
                    TextInput::make('tier')->label('Tier')->disabled()->dehydrated(false)
                        ->formatStateUsing(fn (?string $state) => config("loyalty.tiers.{$state}.label", $state)),
                    TextInput::make('points_balance')->label('Saldo poin')->disabled()->dehydrated(false)->numeric(),
                ]),
        ]);
    }

    /** Nomor WhatsApp: disimpan sebagai 628…, unik di semua akun (member, kasir, admin). */
    public static function phoneInput(): TextInput
    {
        return TextInput::make('phone')
            ->label('Nomor WhatsApp')
            ->tel()
            ->maxLength(20)
            ->dehydrateStateUsing(fn (?string $state) => Phone::normalize($state))
            ->rule(fn (?User $record): Closure => function (string $attribute, $value, Closure $fail) use ($record) {
                $phone = Phone::normalize($value);
                if (! $phone) {
                    $fail('Nomor WhatsApp tidak valid.');

                    return;
                }
                if (User::where('phone', $phone)->whereKeyNot($record?->getKey())->exists()) {
                    $fail('Nomor ini sudah dipakai akun lain.');
                }
            });
    }

    public static function avatarUpload(): FileUpload
    {
        return FileUpload::make('avatar_path')
            ->hiddenLabel()
            ->disk('media')
            ->directory('avatars')
            ->visibility('public')
            ->avatar()
            ->image()
            ->imageEditor()
            ->circleCropper()
            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
            ->maxSize(5120)
            ->alignCenter();
    }
}
