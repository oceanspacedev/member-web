<?php

namespace App\Filament\Resources\PanelUsers\Schemas;

use App\Filament\Resources\Users\Schemas\UserForm;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class PanelUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    Section::make('Akun')
                        ->columnSpan(2)
                        ->columns(2)
                        ->schema([
                            TextInput::make('name')
                                ->label('Nama')
                                ->required()
                                ->maxLength(100),
                            TextInput::make('email')
                                ->email()
                                ->required()
                                ->maxLength(150)
                                ->unique(ignoreRecord: true)
                                ->helperText('Untuk login dengan email + password.'),
                            UserForm::phoneInput()
                                ->helperText('Opsional — untuk login dengan OTP WhatsApp.'),
                            TextInput::make('password')
                                ->label(fn (string $operation) => $operation === 'create' ? 'Password' : 'Password baru')
                                ->password()
                                ->revealable()
                                ->rule(Password::min(10))
                                ->required(fn (string $operation) => $operation === 'create')
                                ->dehydrated(fn (?string $state) => filled($state))
                                ->live(onBlur: true)
                                ->helperText(fn (string $operation) => $operation === 'create' ? 'Minimal 10 karakter.' : 'Kosongkan bila tidak diganti.'),
                            TextInput::make('password_confirmation')
                                ->label('Ulangi password')
                                ->password()
                                ->revealable()
                                ->same('password')
                                ->required(fn (Get $get) => filled($get('password')))
                                ->visible(fn (Get $get, string $operation) => $operation === 'create' || filled($get('password')))
                                ->dehydrated(false),
                        ]),
                    Section::make('Foto profil')
                        ->columnSpan(1)
                        ->schema([UserForm::avatarUpload()]),
                ]),
            Section::make('Akses panel')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    self::rolesSelect()
                        // Tidak bisa mengubah peran sendiri: mencegah terkunci dari panel & menaikkan hak sendiri.
                        ->disabled(fn (?User $record) => $record?->is(auth()->user()) ?? false)
                        ->helperText(fn (?User $record) => $record?->is(auth()->user())
                            ? 'Peran akun sendiri tidak bisa diubah.'
                            : 'Menentukan menu & aksi yang bisa dibuka. Izin tiap peran diatur di menu Peran & izin.'),
                    Select::make('outlet_id')
                        ->label('Outlet')
                        ->relationship('outlet', 'name')
                        ->searchable()
                        ->preload()
                        ->helperText('Opsional — dipakai bila admin ini juga mencatat poin lewat Mode Kasir di aplikasi.'),
                ]),
        ]);
    }

    public static function rolesSelect(): Select
    {
        return Select::make('roles')
            ->label('Peran')
            ->relationship('roles', 'name', modifyQueryUsing: fn (Builder $query) => self::assignableRoles($query))
            ->getOptionLabelFromRecordUsing(fn (Role $record) => self::roleLabel($record->name))
            ->multiple()
            ->preload()
            ->required()
            ->nestedRecursiveRule(self::assignableRoleRule(...));
    }

    /** Hanya super admin yang boleh memberikan peran super admin. */
    public static function assignableRoles(Builder $query): Builder
    {
        return auth()->user()?->isSuperAdmin()
            ? $query
            : $query->where('name', '!=', User::SUPER_ADMIN);
    }

    /** Opsi yang disembunyikan juga ditolak di server, bukan hanya tidak tampil di daftar. */
    public static function assignableRoleRule(): In
    {
        return Rule::in(self::assignableRoles(Role::query())->pluck('id')->all());
    }

    public static function roleLabel(string $name): string
    {
        return Str::headline($name);
    }
}
