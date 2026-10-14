<?php

namespace App\Services;

use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountService
{
    /**
     * Hapus akun member (dari panel atau oleh member sendiri di aplikasi).
     *
     * Data pribadi dihapus permanen dan semua sesi login diputus; baris akun disembunyikan
     * (soft delete) tetapi tidak dibuang, sehingga riwayat poin, voucher, dan pembayaran tetap
     * tercatat tanpa identitas. Nomor WhatsApp-nya bisa dipakai mendaftar lagi sebagai akun baru.
     */
    public function delete(User $user, ?User $by = null): void
    {
        DB::transaction(function () use ($user, $by) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            // Poin yang hangus dicatat agar saldo kewajiban poin di laporan tetap cocok.
            if ($user->points_balance > 0) {
                PointTransaction::create([
                    'user_id' => $user->id,
                    'cashier_id' => $by?->id,
                    'type' => PointTransaction::TYPE_ADJUST,
                    'amount' => 0,
                    'points' => -$user->points_balance,
                    'balance_after' => 0,
                    'description' => 'Poin hangus: akun dihapus',
                ]);
            }

            // Voucher yang belum dipakai hangus (kode QR-nya tidak bisa lagi dipakai di kasir).
            $user->vouchers()->active()->update(['expires_at' => now()]);

            $user->tokens()->delete();
            $user->syncRoles([]);

            // avatar_path dikosongkan → file fotonya ikut dihapus (DeletesReplacedMedia).
            $user->forceFill([
                'name' => 'Akun dihapus',
                'phone' => null,
                'email' => null,
                'google_id' => null,
                'birth_date' => null,
                'avatar_path' => null,
                'password' => null,
                'remember_token' => null,
                'phone_verified_at' => null,
                'email_verified_at' => null,
                'last_latitude' => null,
                'last_longitude' => null,
                'role' => User::ROLE_MEMBER,
                'outlet_id' => null,
                'points_balance' => 0,
            ])->save();

            $user->delete();
        });
    }
}
