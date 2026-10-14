<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Verifikasi otomatis: sistem membaca foto struk, mencocokkan outlet, mengecek tanda editan,
        // lalu memutuskan sendiri. Member tidak lagi memilih outlet, jadi outlet_id boleh kosong
        // sampai struk terbaca.
        Schema::table('receipt_claims', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->change();
            $table->timestamp('photo_taken_at')->nullable(); // dari EXIF bila ada
            $table->json('reading')->nullable(); // hasil baca mentah dari foto
            $table->json('flags')->nullable(); // alasan perlu diperiksa admin
            // auto_approved | auto_rejected | needs_review; null = belum diperiksa sistem
            $table->string('decision', 16)->nullable();
            $table->timestamp('verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('receipt_claims', function (Blueprint $table) {
            $table->dropColumn(['photo_taken_at', 'reading', 'flags', 'decision', 'verified_at']);
        });
    }
};
