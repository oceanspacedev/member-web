<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Teks besar" promo jadi opsional: banner yang desain gambarnya sudah berisi teks tidak perlu
     * teks tambahan dari aplikasi.
     */
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            $table->string('highlight', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('promos')->whereNull('highlight')->update(['highlight' => '']);

        Schema::table('promos', function (Blueprint $table) {
            $table->string('highlight', 32)->nullable(false)->change();
        });
    }
};
