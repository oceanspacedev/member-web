<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kunci pembanding nomor struk yang tahan salah baca (lihat ReceiptClaim::matchKey), agar foto ulang
 * struk yang sama tetap dikenali walau satu karakter nomornya terbaca berbeda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipt_claims', function (Blueprint $table) {
            $table->string('receipt_key', 100)->nullable()->after('receipt_number');
            $table->index(['outlet_id', 'receipt_key']);
        });

        // Isi untuk klaim lama. Rumusnya disalin di sini supaya migrasi tidak berubah bila model berubah.
        $key = function (string $number): string {
            $plain = preg_replace('/[^A-Z0-9]/', '', strtoupper(preg_replace('/\s+/', '', trim($number)) ?? '')) ?? '';

            return strtr($plain, ['O' => '0', 'Q' => '0', 'I' => '1', 'L' => '1', 'Z' => '2', 'S' => '5', 'G' => '6', 'B' => '8']);
        };

        DB::table('receipt_claims')->whereNotNull('receipt_number')->orderBy('id')
            ->chunkById(500, function ($claims) use ($key) {
                foreach ($claims as $claim) {
                    DB::table('receipt_claims')->where('id', $claim->id)->update(['receipt_key' => $key($claim->receipt_number)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('receipt_claims', function (Blueprint $table) {
            $table->dropIndex(['outlet_id', 'receipt_key']);
            $table->dropColumn('receipt_key');
        });
    }
};
