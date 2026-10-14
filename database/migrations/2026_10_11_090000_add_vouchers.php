<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            // info = hanya tampil · claim = klaim gratis · sale = tukar poin · birthday = otomatis di bulan ulang tahun
            $table->string('type', 16)->default('info')->after('brand_id');
            $table->unsignedInteger('voucher_value')->nullable()->after('type');
            $table->unsignedInteger('min_spend')->default(0)->after('voucher_value');
            $table->unsignedInteger('price_points')->nullable()->after('min_spend');
            $table->string('min_tier', 16)->nullable()->after('price_points'); // null = semua tier
            $table->unsignedInteger('quota')->nullable()->after('min_tier'); // null = tanpa batas
            $table->unsignedInteger('issued_count')->default(0)->after('quota');
            $table->unsignedTinyInteger('per_member_limit')->default(1)->after('issued_count');
            $table->unsignedSmallInteger('valid_days')->nullable()->after('per_member_limit'); // null = sampai promo berakhir
        });

        // Voucher milik member. Nilai, judul, dan brand disalin saat terbit agar perubahan
        // atau penghapusan promo/brand tidak mengubah voucher yang sudah dipegang member.
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->string('source', 16); // claim | purchase | birthday
            $table->string('period', 16)->nullable(); // voucher ulang tahun: tahunnya, agar terbit sekali per tahun
            $table->string('title');
            $table->unsignedBigInteger('brand_id')->nullable()->index(); // null = semua outlet
            $table->unsignedInteger('value');
            $table->unsignedInteger('min_spend')->default(0);
            $table->unsignedInteger('points_spent')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 64)->nullable();
            $table->unsignedBigInteger('bill_amount')->nullable();
            $table->unsignedInteger('discount')->nullable();
            $table->timestamps();

            $table->unique(['promo_id', 'user_id', 'period']);
            // Satu struk hanya boleh memakai satu voucher.
            $table->unique(['outlet_id', 'reference']);
            $table->index(['user_id', 'used_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');

        Schema::table('promos', function (Blueprint $table) {
            $table->dropColumn(['type', 'voucher_value', 'min_spend', 'price_points', 'min_tier', 'quota', 'issued_count', 'per_member_limit', 'valid_days']);
        });
    }
};
