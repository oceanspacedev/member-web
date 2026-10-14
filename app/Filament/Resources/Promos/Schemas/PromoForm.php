<?php

namespace App\Filament\Resources\Promos\Schemas;

use App\Models\Promo;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PromoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    Section::make('Isi promo')
                        ->columnSpan(2)
                        ->columns(2)
                        ->schema([
                            TextInput::make('title')
                                ->label('Judul')
                                ->required()
                                ->maxLength(150)
                                ->columnSpanFull(),
                            TextInput::make('highlight')
                                ->label('Teks besar')
                                ->helperText('Mis. 20%, Gratis, B1G1, Poin 3x')
                                ->required()
                                ->maxLength(32),
                            TextInput::make('highlight_caption')
                                ->label('Keterangan kecil')
                                ->helperText('Mis. diskon, akhir pekan')
                                ->maxLength(32),
                            Textarea::make('description')
                                ->label('Deskripsi')
                                ->rows(3)
                                ->columnSpanFull(),
                            Textarea::make('terms')
                                ->label('Syarat & ketentuan')
                                ->rows(3)
                                ->columnSpanFull(),
                        ]),
                    Section::make('Tayang')
                        ->columnSpan(1)
                        ->schema([
                            Select::make('type')
                                ->label('Jenis promo')
                                ->options(Promo::TYPES)
                                ->default(Promo::TYPE_INFO)
                                ->required()
                                ->native(false)
                                ->live()
                                ->helperText(fn (Get $get) => match ($get('type')) {
                                    Promo::TYPE_CLAIM => 'Member klaim voucher gratis dari aplikasi.',
                                    Promo::TYPE_SALE => 'Member menukar poin dengan voucher.',
                                    Promo::TYPE_BIRTHDAY => 'Voucher terbit otomatis di bulan ulang tahun member, tidak tampil di katalog.',
                                    default => 'Hanya informasi; member cukup datang ke outlet.',
                                }),
                            Select::make('brand_id')
                                ->label('Berlaku di')
                                ->relationship('brand', 'name')
                                ->placeholder('Semua outlet')
                                ->searchable()
                                ->preload(),
                            DatePicker::make('starts_at')
                                ->label('Mulai')
                                ->native(false)
                                ->displayFormat('d M Y')
                                ->default(now())
                                ->required(),
                            DatePicker::make('ends_at')
                                ->label('Berakhir')
                                ->native(false)
                                ->displayFormat('d M Y')
                                ->afterOrEqual('starts_at')
                                ->required(),
                            Select::make('min_tier')
                                ->label('Untuk tier')
                                ->options(collect(config('loyalty.tiers'))->except('silver')->map(fn ($tier) => $tier['label'].' ke atas')->all())
                                ->placeholder('Semua member')
                                ->native(false),
                            Toggle::make('is_active')
                                ->label('Aktif')
                                ->default(true),
                        ]),
                ]),
            Section::make('Voucher')
                ->description('Nilai, judul, dan brand disalin ke voucher saat terbit — mengubah promo tidak mengubah voucher yang sudah dipegang member.')
                ->columnSpanFull()
                ->columns(3)
                ->visible(fn (Get $get) => $get('type') !== null && $get('type') !== Promo::TYPE_INFO)
                ->schema([
                    TextInput::make('voucher_value')
                        ->label('Nilai potongan')
                        ->prefix('Rp')
                        ->integer()
                        ->minValue(1000)
                        ->required(),
                    TextInput::make('min_spend')
                        ->label('Minimal belanja')
                        ->prefix('Rp')
                        ->integer()
                        ->minValue(0)
                        ->default(0),
                    TextInput::make('price_points')
                        ->label('Harga dalam poin')
                        ->suffix('poin')
                        ->integer()
                        ->minValue(1)
                        ->live(onBlur: true)
                        ->helperText(fn (?string $state) => $state ? 'Senilai Rp'.number_format((int) $state * config('loyalty.point_value'), 0, ',', '.').' (1 poin = Rp'.config('loyalty.point_value').')' : 'Kosongkan bila hanya dijual dengan uang.')
                        ->visible(fn (Get $get) => $get('type') === Promo::TYPE_SALE)
                        ->required(fn (Get $get) => $get('type') === Promo::TYPE_SALE && blank($get('price_rupiah')))
                        ->validationMessages(['required' => 'Isi harga poin atau harga rupiah.']),
                    TextInput::make('price_rupiah')
                        ->label('Harga jual (bayar online)')
                        ->prefix('Rp')
                        ->integer()
                        ->minValue(1000)
                        ->live(onBlur: true)
                        ->helperText('Dibayar lewat Xendit (QRIS, VA, e-wallet). Kosongkan bila hanya ditukar poin.')
                        ->visible(fn (Get $get) => $get('type') === Promo::TYPE_SALE)
                        ->required(fn (Get $get) => $get('type') === Promo::TYPE_SALE && blank($get('price_points')))
                        ->validationMessages(['required' => 'Isi harga poin atau harga rupiah.']),
                    TextInput::make('quota')
                        ->label('Kuota total')
                        ->integer()
                        ->minValue(1)
                        ->placeholder('Tanpa batas')
                        ->helperText(fn (?Promo $record) => $record?->issued_count ? "Sudah terbit {$record->issued_count} voucher." : null)
                        ->visible(fn (Get $get) => $get('type') !== Promo::TYPE_BIRTHDAY),
                    TextInput::make('per_member_limit')
                        ->label('Maks. per member')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(20)
                        ->default(1)
                        ->required()
                        ->visible(fn (Get $get) => $get('type') !== Promo::TYPE_BIRTHDAY),
                    TextInput::make('valid_days')
                        ->label('Berlaku setelah diambil')
                        ->suffix('hari')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(365)
                        ->placeholder('Sampai promo berakhir')
                        ->visible(fn (Get $get) => $get('type') !== Promo::TYPE_BIRTHDAY),
                ]),
            Section::make('Gambar promo (16:9)')
                ->description('Foto produk asli, tanpa teks di dalam gambar — judul & teks besar ditulis aplikasi.')
                ->schema([
                    FileUpload::make('image_path')
                        ->hiddenLabel()
                        ->disk('media')
                        ->directory('promos')
                        ->visibility('public')
                        ->image()
                        ->imageEditor()
                        ->imageCropAspectRatio('16:9')
                        ->imageResizeTargetWidth('1200')
                        ->imageResizeTargetHeight('675')
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                        ->maxSize(5120),
                ]),
        ]);
    }
}
