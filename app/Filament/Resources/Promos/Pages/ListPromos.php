<?php

namespace App\Filament\Resources\Promos\Pages;

use App\Filament\Resources\Promos\PromoResource;
use App\Models\Outlet;
use App\Models\Promo;
use App\Services\VoucherCodeImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ListPromos extends ListRecords
{
    protected static string $resource = PromoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Satu langkah: pilih outlet + file → promo dibuat otomatis per nilai diskon dan langsung tampil di daftar.
            Action::make('importPosVouchers')
                ->label('Impor voucher POS')
                ->color('gray')
                ->authorize(fn () => auth()->user()?->can('create', Promo::class) ?? false)
                ->modalHeading('Impor voucher dari POS outlet')
                ->modalDescription('Unggah daftar kode voucher hasil ekspor POS (.xlsx/.csv) dengan kolom Kode, Diskon, dan (opsional) Kadaluarsa & Penggunaan. Promo dibuat otomatis per nilai diskon, mis. "Voucher Rp5.000 – Ulon Ciledug". '
                    .'Impor ulang file terbaru dari POS menandai voucher yang sudah dipakai di kasir.')
                ->schema([
                    Select::make('outlet_id')
                        ->label('Outlet asal kode')
                        ->options(fn () => Outlet::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                    FileUpload::make('file')
                        ->label('File daftar voucher')
                        ->disk('local')
                        ->directory('imports/voucher-codes')
                        ->visibility('private')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel',
                        ])
                        ->maxSize(5120)
                        ->required(),
                    Select::make('type')
                        ->label('Cara member mendapatkan')
                        ->options([Promo::TYPE_CLAIM => 'Klaim gratis', Promo::TYPE_SALE => 'Tukar poin'])
                        ->default(Promo::TYPE_CLAIM)
                        ->required()
                        ->live(),
                    TextInput::make('price_points')
                        ->label('Harga poin')
                        ->suffix('poin')
                        ->integer()
                        ->minValue(1)
                        ->visible(fn (Get $get) => $get('type') === Promo::TYPE_SALE)
                        ->required(fn (Get $get) => $get('type') === Promo::TYPE_SALE),
                    DatePicker::make('ends_at')
                        ->label('Promo tayang sampai')
                        ->default(now()->addMonth())
                        ->minDate(now()->startOfDay())
                        ->required(),
                ])
                ->modalSubmitActionLabel('Impor')
                ->action(function (array $data) {
                    $disk = Storage::disk('local');
                    try {
                        $summary = app(VoucherCodeImporter::class)->import(
                            Outlet::findOrFail($data['outlet_id']),
                            $disk->path($data['file']),
                            pathinfo($data['file'], PATHINFO_EXTENSION),
                            ['type' => $data['type'], 'price_points' => $data['price_points'] ?? null, 'ends_at' => $data['ends_at']],
                        );
                    } catch (ValidationException $e) {
                        Notification::make()->title('Impor gagal')->body(collect($e->errors())->flatten()->first())->danger()->persistent()->send();

                        return;
                    } finally {
                        $disk->delete($data['file']);
                    }

                    Notification::make()
                        ->title('Impor voucher POS selesai')
                        ->body(new HtmlString(nl2br(e(VoucherCodeImporter::describe($summary)))))
                        ->success()
                        ->persistent()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
