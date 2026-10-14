<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Tables\UsersTable;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\AccountService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UsersTable::recordPurchaseAction()->after(fn () => $this->refreshFormData(['points_balance', 'tier'])),
            UsersTable::adjustPointsAction()->after(fn () => $this->refreshFormData(['points_balance'])),
            ActionGroup::make([
                $this->logoutEverywhereAction(),
                $this->deleteAccountAction(),
            ])->label('Lainnya')->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray'),
        ];
    }

    /** Putus semua sesi login member (mis. HP hilang); member perlu masuk ulang dengan OTP. */
    private function logoutEverywhereAction(): Action
    {
        return Action::make('logoutEverywhere')
            ->label('Keluarkan dari semua perangkat')
            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->requiresConfirmation()
            ->modalDescription('Member harus masuk ulang dengan OTP WhatsApp di semua perangkat.')
            ->disabled(fn (User $record) => ! $record->tokens()->exists())
            ->action(function (User $record) {
                $count = $record->tokens()->delete();
                Notification::make()->title("Keluar dari {$count} perangkat")->success()->send();
            });
    }

    /** Hapus akun: data pribadi dihapus, riwayat transaksi tetap ada tanpa identitas. */
    private function deleteAccountAction(): Action
    {
        return Action::make('deleteAccount')
            ->label('Hapus akun')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (User $record) => UserResource::canDelete($record))
            ->requiresConfirmation()
            ->modalHeading(fn (User $record) => 'Hapus akun '.($record->name ?? $record->member_code).'?')
            ->modalDescription('Nama, nomor WhatsApp, email, foto, dan tanggal lahir dihapus permanen; saldo poin dan voucher aktif hangus; semua sesi login diputus. Riwayat transaksi tetap disimpan tanpa identitas untuk laporan. Tidak bisa dibatalkan.')
            ->modalSubmitActionLabel('Ya, hapus akun')
            ->action(function (User $record) {
                app(AccountService::class)->delete($record);
                Notification::make()->title('Akun dihapus')->success()->send();
                $this->redirect(UserResource::getUrl());
            });
    }

    /** Peran & outlet tidak mass-assignable di model, jadi diisi eksplisit di sini. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $data = Arr::only($data, UserResource::EDITABLE);

        // Kasir/admin yang diturunkan jadi member tidak lagi terikat outlet.
        if (($data['role'] ?? $record->role) === User::ROLE_MEMBER) {
            $data['outlet_id'] = null;
        }

        $record->forceFill($data)->save();

        return $record;
    }
}
