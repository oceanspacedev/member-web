<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Akun yang dihapus tetap ada sebagai baris tanpa identitas, agar riwayat poin,
        // voucher, dan pembayaran tetap utuh untuk laporan.
        Schema::table('users', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
