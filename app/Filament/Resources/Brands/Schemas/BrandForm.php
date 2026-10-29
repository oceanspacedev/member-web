<?php

namespace App\Filament\Resources\Brands\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi brand')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nama brand')
                        ->required()
                        ->maxLength(100)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, ?string $state, string $operation) => $operation === 'create' ? $set('slug', Str::slug((string) $state)) : null),
                    TextInput::make('slug')
                        ->required()
                        ->maxLength(100)
                        ->alphaDash()
                        ->unique(ignoreRecord: true),
                    Select::make('category')
                        ->label('Kategori')
                        ->options(config('loyalty.brand_categories'))
                        ->required()
                        ->native(false),
                    TextInput::make('legal_entity')
                        ->label('Entitas hukum')
                        ->placeholder('CV. / PT. ...')
                        ->maxLength(150),
                    ColorPicker::make('color')
                        ->label('Warna brand')
                        ->helperText('Dipakai sebagai warna latar saat foto belum ada.')
                        ->default('#3F3F46')
                        ->required(),
                    Textarea::make('description')
                        ->label('Deskripsi')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
            Section::make('Logo resmi')
                ->description('Tampil di daftar outlet, detail outlet, dan riwayat poin aplikasi member.')
                ->schema([
                    FileUpload::make('logo_path')
                        ->label('Logo')
                        ->disk('media')
                        ->directory('brands')
                        ->visibility('public')
                        ->image()
                        ->imageEditor()
                        ->imageCropAspectRatio('1:1')
                        ->imageResizeTargetWidth('512')
                        ->imageResizeTargetHeight('512')
                        // SVG tidak diterima: bisa membawa skrip dan disajikan dari domain yang sama dengan API.
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                        ->maxSize(3072),
                ]),
            Section::make('Ciri struk')
                ->description('Dibaca AI saat memeriksa foto struk member: untuk mengenali struk asli brand ini dan menolak struk dari toko lain. Tidak tampil di aplikasi.')
                ->schema([
                    Textarea::make('receipt_guide')
                        ->label('Ciri struk brand ini')
                        ->placeholder('Contoh: Struk thermal berkop "NAMA OUTLET", nomor struk = "No. Kwitansi #...", tanggal DD/MM/YYYY, total = "TOTAL". Slip "INTERNAL" bukan bukti belanja.')
                        ->helperText('Tulis kop/nama di struk, format nomor struk, format tanggal, letak total, dan jenis nota yang bukan belanja member.')
                        ->rows(6)
                        ->maxLength(4000),
                ]),
        ]);
    }
}
