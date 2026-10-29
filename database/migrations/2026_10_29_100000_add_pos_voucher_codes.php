<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode voucher dari POS outlet (mis. daftar voucher di POS Ulon): diimpor admin ke sebuah promo, lalu
 * dibagikan satu per member. Kasir memasukkan kodenya di POS outlet itu, dan POS yang memotong tagihan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            // null = kode dibuat Space Club; diisi = member menerima kode impor dari POS outlet ini.
            $table->foreignId('pos_outlet_id')->nullable()->after('brand_id')->constrained('outlets')->nullOnDelete();
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->string('code', 40)->change(); // kode POS bisa lebih panjang dari kode Space Club
            $table->foreignId('pos_outlet_id')->nullable()->after('code')->constrained('outlets')->nullOnDelete();
        });

        Schema::create('voucher_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->string('pos_title')->nullable();
            $table->unsignedInteger('value')->nullable(); // potongan menurut POS; null = ikut nilai promo
            $table->date('starts_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->unsignedSmallInteger('max_uses')->default(1);
            $table->unsignedSmallInteger('used_count')->default(0);
            $table->foreignId('voucher_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['promo_id', 'voucher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_codes');

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_outlet_id');
            $table->string('code', 16)->change();
        });

        Schema::table('promos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_outlet_id');
        });
    }
};
