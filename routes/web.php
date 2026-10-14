<?php

use App\Http\Controllers\LandingController;
use App\Models\ReceiptClaim;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Landing page publik dan tautan pendek unduh (untuk QR, poster, dan bio media sosial).
Route::get('/', [LandingController::class, 'show'])->name('landing');
Route::get('unduh', [LandingController::class, 'download'])->name('download');

// Halaman hukum publik: tautan wajib untuk App Store & Play Store, dibuka juga dari aplikasi.
Route::view('kebijakan-privasi', 'legal.privacy')->name('legal.privacy');
Route::view('syarat-ketentuan', 'legal.terms')->name('legal.terms');
Route::view('hapus-akun', 'legal.delete-account')->name('legal.delete-account');

// Foto struk klaim poin: disimpan privat, hanya untuk admin yang boleh melihat klaim.
Route::get('receipt-photos/{receiptClaim}', function (ReceiptClaim $receiptClaim) {
    return Storage::disk(ReceiptClaim::DISK)->response($receiptClaim->photo_path, headers: ['Cache-Control' => 'private, max-age=3600']);
})->middleware(['auth', 'can:view,receiptClaim'])->name('receipt-photos.show');
