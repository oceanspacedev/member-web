<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Buat atau perbarui akun admin panel web.
 *   php artisan admin:create admin@contoh.com --name="Nama" --phone=0812...
 * Password diminta secara tersembunyi (tidak tampil di layar / riwayat terminal).
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email} {--name=Admin} {--phone= : Nomor WhatsApp agar bisa masuk lewat OTP juga}';

    protected $description = 'Buat atau perbarui akun super admin panel (login email + password, opsional WhatsApp)';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $phone = $this->option('phone') ? Phone::normalize($this->option('phone')) : null;

        if ($this->option('phone') && ! $phone) {
            $this->error('Nomor WhatsApp tidak valid.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (min. 10 karakter)');
        $confirm = (string) $this->secret('Ulangi password');

        $validator = Validator::make(compact('email', 'password', 'confirm'), [
            'email' => ['required', 'email'],
            'password' => ['required', 'min:10', 'same:confirm'],
        ], ['password.same' => 'Konfirmasi password tidak sama.']);

        if ($validator->fails()) {
            collect($validator->errors()->all())->each(fn ($m) => $this->error($m));

            return self::FAILURE;
        }

        if ($phone && User::where('phone', $phone)->where('email', '!=', $email)->exists()) {
            $this->error('Nomor WhatsApp itu sudah dipakai akun lain.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $user->name ?? $this->option('name'),
            'password' => $password,
            'role' => User::ROLE_ADMIN,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ] + ($phone ? ['phone' => $phone, 'phone_verified_at' => now()] : []))->save();

        // Dibuat dari server = pemilik sistem: semua izin panel. Admin lain diatur di menu Pengguna panel.
        $user->grantSuperAdmin();

        $this->info(($user->wasRecentlyCreated ? 'Admin dibuat: ' : 'Admin diperbarui: ').$email);

        return self::SUCCESS;
    }
}
