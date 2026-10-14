<?php

namespace App\Filament\Resources\Outlets\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OutletForm
{
    private const DAYS = [
        'mon' => 'Senin', 'tue' => 'Selasa', 'wed' => 'Rabu', 'thu' => 'Kamis',
        'fri' => 'Jumat', 'sat' => 'Sabtu', 'sun' => 'Minggu',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    Section::make('Informasi outlet')
                        ->columnSpan(2)
                        ->columns(2)
                        ->schema([
                            Select::make('brand_id')
                                ->label('Brand')
                                ->relationship('brand', 'name')
                                ->searchable()
                                ->preload()
                                ->required(),
                            TextInput::make('name')
                                ->label('Nama outlet')
                                ->required()
                                ->maxLength(120),
                            TextInput::make('city')
                                ->label('Kota')
                                ->maxLength(80),
                            TextInput::make('phone')
                                ->label('Telepon')
                                ->tel()
                                ->maxLength(20),
                            Textarea::make('address')
                                ->label('Alamat lengkap')
                                ->rows(2)
                                ->columnSpanFull(),
                            TextInput::make('whatsapp')
                                ->label('WhatsApp outlet')
                                ->tel()
                                ->placeholder('0812…')
                                ->maxLength(20),
                            TextInput::make('menu_url')
                                ->label('Link katalog menu/produk')
                                ->url()
                                ->maxLength(255),
                        ]),
                    Section::make('Status')
                        ->columnSpan(1)
                        ->schema([
                            Toggle::make('is_active')
                                ->label('Tampil di aplikasi')
                                ->default(true),
                            FileUpload::make('photo_path')
                                ->label('Foto outlet (4:3)')
                                ->disk('media')
                                ->directory('outlets')
                                ->visibility('public')
                                ->image()
                                ->imageEditor()
                                ->imageCropAspectRatio('4:3')
                                ->imageResizeTargetWidth('1200')
                                ->imageResizeTargetHeight('900')
                                ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                                ->maxSize(5120),
                        ]),
                ]),
            Section::make('Lokasi')
                ->description('Koordinat dipakai untuk mengurutkan outlet terdekat di aplikasi.')
                ->columns(3)
                ->schema([
                    TextInput::make('latitude')
                        ->numeric()
                        ->minValue(-90)
                        ->maxValue(90),
                    TextInput::make('longitude')
                        ->numeric()
                        ->minValue(-180)
                        ->maxValue(180),
                    TextInput::make('maps_url')
                        ->label('Link Google Maps')
                        ->url()
                        ->maxLength(255),
                ]),
            Section::make('Jam operasional')
                ->description('Format 10:00-22:00. Kosongkan bila tutup di hari itu.')
                ->columns(4)
                ->schema(collect(self::DAYS)->map(
                    fn (string $label, string $key) => TextInput::make("opening_hours.{$key}")
                        ->label($label)
                        ->placeholder('10:00-22:00')
                        ->regex('/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/')
                        ->validationMessages(['regex' => 'Gunakan format JJ:MM-JJ:MM, misalnya 10:00-22:00.'])
                )->values()->all()),
        ]);
    }
}
