<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jejak pemakaian aplikasi untuk panel admin. Disimpan di users (bukan hanya di token)
        // agar tetap ada setelah member keluar dan tokennya dihapus.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
            $table->timestamp('last_seen_at')->nullable()->index()->after('last_login_at');
            $table->string('last_device', 40)->nullable()->after('last_seen_at');
        });

        // Isi dari sesi login yang sudah ada.
        DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')
            ->orderBy('created_at')
            ->get(['tokenable_id', 'name', 'created_at', 'last_used_at'])
            ->groupBy('tokenable_id')
            ->each(function ($tokens, $userId) {
                $latest = $tokens->last();

                DB::table('users')->where('id', $userId)->update([
                    'last_login_at' => $latest->created_at,
                    'last_seen_at' => $tokens->max('last_used_at') ?? $latest->created_at,
                    'last_device' => $latest->name,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn(['last_login_at', 'last_seen_at', 'last_device']);
        });
    }
};
