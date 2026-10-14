<?php

use App\Models\User;
use App\Models\Voucher;
use App\Services\VoucherService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Voucher ulang tahun juga terbit saat member membuka aplikasi; jadwal ini memastikan
// voucher sudah ada di awal bulan meski member belum membuka aplikasi.
Artisan::command('vouchers:birthday', function (VoucherService $vouchers) {
    $before = Voucher::where('source', Voucher::SOURCE_BIRTHDAY)->count();

    User::whereNotNull('birth_date')
        ->whereMonth('birth_date', now(config('loyalty.timezone'))->month)
        ->chunkById(500, fn ($members) => $members->each(fn (User $member) => $vouchers->issueBirthdayVouchers($member)));

    $this->info('Voucher ulang tahun baru: '.(Voucher::where('source', Voucher::SOURCE_BIRTHDAY)->count() - $before));
})->purpose('Terbitkan voucher ulang tahun untuk member yang lahir bulan ini');

Schedule::command('vouchers:birthday')->dailyAt('07:00')->timezone(config('loyalty.timezone'));
