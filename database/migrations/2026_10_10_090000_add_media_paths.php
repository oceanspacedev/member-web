<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gambar disimpan sebagai path di disk "media" (public/media), mis. "promos/abc.webp".
 * URL lengkap dibentuk saat respons API (App\Support\Media::url).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->renameColumn('logo_url', 'logo_path');
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('maps_url');
        });

        Schema::table('promos', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('terms');
        });
    }

    public function down(): void
    {
        Schema::table('promos', fn (Blueprint $table) => $table->dropColumn('image_path'));
        Schema::table('outlets', fn (Blueprint $table) => $table->dropColumn('photo_path'));
        Schema::table('brands', fn (Blueprint $table) => $table->renameColumn('logo_path', 'logo_url'));
    }
};
