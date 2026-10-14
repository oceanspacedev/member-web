<?php

namespace App\Filament\Resources\PartnerKeys\Pages;

use App\Filament\Resources\PartnerKeys\PartnerKeyResource;
use App\Models\Outlet;
use App\Models\PartnerKey;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ListPartnerKeys extends ListRecords
{
    protected static string $resource = PartnerKeyResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'API key per outlet agar POS (iSeller, Moka, Majoo, Olsera, dll.) bisa mengecek dan memakai voucher member. Dokumentasi: menu Dokumentasi API, bagian "Partner POS".';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label('Buat API key')
                ->icon(Heroicon::OutlinedPlus)
                ->authorize('create', PartnerKey::class)
                ->schema([
                    Select::make('outlet_id')
                        ->label('Outlet')
                        ->options(fn () => Outlet::with('brand')->where('is_active', true)->orderBy('name')->get()
                            ->mapWithKeys(fn (Outlet $o) => [$o->id => $o->brand ? "{$o->name} ({$o->brand->name})" : $o->name]))
                        ->searchable()
                        ->required(),
                    TextInput::make('name')
                        ->label('Nama')
                        ->placeholder('mis. iSeller Ulon Ciledug')
                        ->helperText('Untuk mengenali POS/vendor pemakai key ini.')
                        ->required()
                        ->maxLength(100),
                ])
                ->action(function (array $data): void {
                    [, $plain] = PartnerKey::issue($data['name'], Outlet::findOrFail($data['outlet_id']));

                    // Key utuh hanya ditampilkan sekali; yang tersimpan hanya hash-nya.
                    $this->replaceMountedAction('showKey', ['key' => $plain]);
                }),
        ];
    }

    public function showKeyAction(): Action
    {
        return Action::make('showKey')
            ->modalHeading('API key dibuat')
            ->modalDescription('Salin dan berikan ke vendor/admin POS sekarang. Key ini tidak akan ditampilkan lagi; bila hilang, cabut lalu buat key baru.')
            ->schema(fn (array $arguments) => [
                TextEntry::make('key')
                    ->label('API key')
                    ->state($arguments['key'] ?? null)
                    ->copyable()
                    ->copyMessage('Disalin')
                    ->fontFamily('mono'),
                TextEntry::make('usage')
                    ->label('Cara pakai')
                    ->state('Header: Authorization: Bearer <API key> · Base URL: '.url('/api/partner/v1')),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Selesai');
    }
}
