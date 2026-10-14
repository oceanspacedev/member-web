<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            // Voucher jual: boleh harga poin, harga rupiah (payment gateway), atau keduanya.
            $table->unsignedInteger('price_rupiah')->nullable()->after('price_points');
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->unsignedInteger('paid_amount')->default(0)->after('points_spent');
        });

        // Pembelian voucher dengan uang. Voucher baru terbit setelah gateway mengonfirmasi lunas.
        Schema::create('voucher_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique(); // dikirim ke gateway sebagai reference_id
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promo_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            // Nilai voucher saat dipesan: dipakai saat lunas, meski promo diubah/dihapus sesudahnya.
            $table->json('snapshot');
            $table->unsignedInteger('amount');
            $table->string('status', 16)->default('pending'); // pending | paid | expired | canceled
            $table->string('gateway', 16);
            $table->string('gateway_session_id', 64)->nullable()->index();
            $table->text('payment_url')->nullable();
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['promo_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_orders');

        Schema::table('vouchers', fn (Blueprint $table) => $table->dropColumn('paid_amount'));
        Schema::table('promos', fn (Blueprint $table) => $table->dropColumn('price_rupiah'));
    }
};
