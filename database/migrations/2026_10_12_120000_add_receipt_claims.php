<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Klaim poin dari foto struk yang dikirim member (outlet memakai POS pihak ketiga).
        // Member cukup memilih outlet dan memfoto struk; admin membaca foto, mengisi nomor struk,
        // total, dan tanggal, lalu menyetujui (poin masuk) atau menolak.
        Schema::create('receipt_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number', 64)->nullable(); // diisi admin saat menyetujui
            $table->unsignedBigInteger('amount')->nullable(); // total belanja, diisi admin
            $table->date('purchased_at')->nullable(); // diisi admin
            $table->string('photo_path'); // disk privat "local", bukan media publik
            $table->string('photo_hash', 64)->index(); // foto yang sama tidak bisa dikirim dua kali
            $table->string('status', 16)->default('pending'); // pending | approved | rejected
            $table->string('reject_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('point_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['outlet_id', 'receipt_number']);
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_claims');
    }
};
