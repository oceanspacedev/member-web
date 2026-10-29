<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ulon Ciledug dan J Chicken Ciledug ada di Ciledug, Kabupaten Cirebon — bukan Ciledug, Tangerang
 * seperti data awal. Koordinat hanya diganti bila masih tebakan lama (perkiraan pusat kecamatan),
 * supaya titik yang sudah diatur admin tidak tertimpa.
 */
return new class extends Migration
{
    /** [nama outlet, koordinat lama (Tangerang), koordinat baru (perkiraan Ciledug, Kab. Cirebon)] */
    private const OUTLETS = [
        ['Ulon Ciledug', [-6.2297, 106.7110], [-6.9010, 108.7400]],
        ['J Chicken Ciledug', [-6.2310, 106.7125], [-6.9020, 108.7415]],
    ];

    public function up(): void
    {
        foreach (self::OUTLETS as [$name, [$oldLat, $oldLng], [$lat, $lng]]) {
            DB::table('outlets')->where('name', $name)->where('city', 'Tangerang')->update(['city' => 'Cirebon']);
            DB::table('outlets')->where('name', $name)
                ->whereBetween('latitude', [$oldLat - 0.00001, $oldLat + 0.00001])
                ->whereBetween('longitude', [$oldLng - 0.00001, $oldLng + 0.00001])
                ->update(['latitude' => $lat, 'longitude' => $lng]);
        }
    }

    public function down(): void
    {
        foreach (self::OUTLETS as [$name, [$oldLat, $oldLng], [$lat, $lng]]) {
            DB::table('outlets')->where('name', $name)->where('city', 'Cirebon')->update(['city' => 'Tangerang']);
            DB::table('outlets')->where('name', $name)
                ->whereBetween('latitude', [$lat - 0.00001, $lat + 0.00001])
                ->whereBetween('longitude', [$lng - 0.00001, $lng + 0.00001])
                ->update(['latitude' => $oldLat, 'longitude' => $oldLng]);
        }
    }
};
