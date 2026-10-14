<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category', 24)->index(); // culinary | fastfood | retail | sports
            $table->string('legal_entity')->nullable();
            $table->string('color', 9)->default('#0E5A43');
            $table->string('logo_url')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('outlets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->json('opening_hours')->nullable();
            $table->string('maps_url')->nullable();
            $table->string('menu_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('promos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete(); // null = berlaku di semua outlet
            $table->string('title');
            $table->string('highlight', 32);
            $table->string('highlight_caption', 32)->nullable();
            $table->text('description')->nullable();
            $table->text('terms')->nullable();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 16); // earn | redeem | adjust
            $table->unsignedBigInteger('amount')->default(0); // nilai belanja / potongan dalam Rupiah
            $table->bigInteger('points'); // + bertambah, - berkurang
            $table->unsignedBigInteger('balance_after');
            $table->string('reference', 64)->nullable(); // nomor struk POS
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'type', 'reference']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_transactions');
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('promos');
        Schema::dropIfExists('outlets');
        Schema::dropIfExists('brands');
    }
};
