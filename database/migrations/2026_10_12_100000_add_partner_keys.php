<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // API key per outlet untuk POS pihak ketiga (iSeller, Moka, Majoo, Olsera, ...) yang memvalidasi voucher.
        // Hanya hash yang disimpan; key utuh ditampilkan sekali saat dibuat.
        Schema::create('partner_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // mis. "iSeller Ulon Ciledug"
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('key_prefix', 16); // awal key untuk dikenali di panel, mis. "osk_AbC12"
            $table->string('key_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('vouchers', function (Blueprint $table) {
            // Voucher yang dipakai lewat API partner: kasirnya ada di POS pihak ketiga, bukan akun kita.
            $table->foreignId('partner_key_id')->nullable()->after('cashier_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_key_id');
        });

        Schema::dropIfExists('partner_keys');
    }
};
