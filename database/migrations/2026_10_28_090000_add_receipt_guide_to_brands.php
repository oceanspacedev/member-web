<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ciri struk per brand untuk pembaca struk otomatis (AI): bentuk struk asli, format nomor, letak total,
 * dan nota yang bukan belanja member. Isi awal disusun dari contoh struk asli tiap outlet (28 Okt 2026);
 * admin bisa mengubahnya di panel. Data hanya diisi bila masih kosong.
 */
return new class extends Migration
{
    private const GUIDES = [
        'complete-mulia' => <<<'TXT'
            Nota penjualan cetak berlogo "TOKO MAS COMPLETE MULIA" (watermark "COPY"): nomor nota di atas berformat CML-FJ-YYYYMMDD-NNNN (FJ = faktur jual); tanggal dan jam di kanan atas (DD-MM-YYYY HH:MM:SS) diikuti nama pelanggan, alamat, kode pelanggan (CML...) dan "POIN SETIA"; tabel KODE BARANG / JENIS BARANG / BERAT / HARGA per gram ditambah Ongkos; total di kanan bawah dan dalam huruf (mis. "DUA JUTA TIGA RATUS EMPAT PULUH RIBU RUPIAH").
            Warna nota menandakan kadar emas dengan desain yang sama: oranye = emas tua, biru = logam mulia, kuning = emas 24K, merah = emas muda. Perbedaan warna bukan tanda palsu.
            Cabang Jl. Pahlawan No. 230 Junjang, Kec. Arjawinangun, Kab. Cirebon = Complete Mulia Arjawinangun.
            Slip thermal "Toko Emas Complete Mulia" bertanda "INTERNAL" dengan "No Faktur Beli" (CML-FB-...) adalah faktur beli: toko membeli emas dari pelanggan, bukan belanja member (transaction_type buyback).
            TXT,
        'toko-mas-an-an' => <<<'TXT'
            Nota penjualan cetak berlogo "TOKO MAS An An Cap Kambing", Jl. Lapang Bola No.57 Ciledug Cirebon (watermark "COPY"), satu grup dengan Complete Mulia: nomor nota di atas berformat ANN-FJ-YYYYMMDD-NNNN (FJ = faktur jual); tanggal dan jam di kanan atas (DD-MM-YYYY HH:MM:SS), kode pelanggan (ANN...) dan "POIN SETIA"; tabel KODE BARANG / JENIS BARANG / BERAT / HARGA per gram ditambah Ongkos; total di kotak "Jumlah Rp" dan dalam huruf. Warna nota bisa berbeda menurut jenis emas; bukan tanda palsu.
            Slip thermal "Toko Emas An An Ciledug" bertanda "INTERNAL" atau "PELANGGAN" dengan "No Faktur Beli" (ANN-FB-...) adalah faktur beli: toko membeli emas dari pelanggan, bukan belanja member (transaction_type buyback).
            TXT,
        'momoyo' => <<<'TXT'
            Struk thermal Momoyo cabang Ciledug Cirebon (Jl. Merdeka Barat No.36, Ciledug Kulon, Kab. Cirebon), dua format:
            1) Kasir toko: kop "Momoyo Ciledug", nomor struk = "No. Kwitansi" (mis. #4-10373), tanggal = "Waktu Pesan" (DD/MM/YYYY HH.MM.SS), total = "TOTAL", lalu metode bayar (Cash/QRIS). "Copy : 2" adalah salinan cetak, bukan tanda palsu.
            2) Pesanan platform pesan-antar: kop "MOMOYO CILEDUG CIREBON", judul "Bawa pulang B63" (B63 = nomor antrean, bukan nomor struk), nomor struk = "Nomor Pesanan" (angka panjang), tanggal = "Waktu Pesanan" (YYYY-MM-DD HH:MM:SS), "Saluran: Platform Pesan Antar", total = "Pembayaran Sebenarnya".
            TXT,
        'ulon-signature' => <<<'TXT'
            Struk thermal Olsera POS gabungan berkop "Ini Kopi Ulon Signature X Ara-Ichi Ramen", Omega Space, Jl Tuparev No.20, Cirebon: nomor struk diawali "#" di bawah tanggal (mis. #397E26102800076893), tanggal = "Waktu Penjualan" (mis. 28 Okt 2026 13:33), total = "Grand Total" (sudah termasuk PB1). "Cetak Ulang" adalah cetak ulang dari mesin kasir, bukan tanda palsu.
            Struk gabungan ini masuk outlet Araichi Ramen bila itemnya ramen atau makanan Jepang; selain itu (kopi, minuman, dll.) masuk Ulon Signature.
            TXT,
        'araichi-ramen' => <<<'TXT'
            Struk thermal Olsera POS gabungan berkop "Ini Kopi Ulon Signature X Ara-Ichi Ramen", Omega Space, Jl Tuparev No.20, Cirebon: nomor struk diawali "#" di bawah tanggal, tanggal = "Waktu Penjualan" (mis. 28 Okt 2026 13:33), total = "Grand Total". "Cetak Ulang" adalah cetak ulang dari mesin kasir, bukan tanda palsu.
            Struk gabungan ini masuk Araichi Ramen bila itemnya ramen atau makanan Jepang; selain itu masuk Ulon Signature.
            TXT,
        'grandmonde' => <<<'TXT'
            Struk thermal Quinos POS berkop "GRANDMONDE", Jln. Siliwangi No.8 Cirebon: nomor struk = "Invoice #" (mis. #016641), tanggal transaksi di bawah nomor invoice dalam format bulan Inggris (mis. Oct 28, 2026 12:36:44 PM), total = "Total" (sudah termasuk Service Charge dan Tax), lalu metode bayar (mis. QRIS Outlet). "Print Cnt" adalah jumlah cetak, bukan tanda palsu.
            TXT,
    ];

    /** Alamat dari struk asli, untuk outlet yang alamatnya belum diisi: [slug brand, nama outlet, alamat, kota]. */
    private const ADDRESSES = [
        ['complete-mulia', 'Complete Mulia Arjawinangun', 'Jl. Pahlawan No. 230 Junjang, Kec. Arjawinangun, Kab. Cirebon', null],
        ['toko-mas-an-an', 'Toko Mas An An', 'Jl. Lapang Bola No. 57, Ciledug, Cirebon', 'Cirebon'],
        ['momoyo', 'Momoyo', 'Jl. Merdeka Barat No. 36, Ciledug Kulon, Kec. Ciledug, Kab. Cirebon', 'Cirebon'],
        ['ulon-signature', 'Ulon Signature', 'Omega Space, Jl. Tuparev No. 20, Cirebon', 'Cirebon'],
        ['araichi-ramen', 'Araichi Ramen', 'Omega Space, Jl. Tuparev No. 20, Cirebon', 'Cirebon'],
        ['grandmonde', 'Grandmonde', 'Jl. Siliwangi No. 8, Cirebon', 'Cirebon'],
    ];

    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->text('receipt_guide')->nullable()->after('description');
        });

        foreach (self::GUIDES as $slug => $guide) {
            DB::table('brands')->where('slug', $slug)->whereNull('receipt_guide')->update(['receipt_guide' => $guide]);
        }

        foreach (self::ADDRESSES as [$slug, $outlet, $address, $city]) {
            $brandId = DB::table('brands')->where('slug', $slug)->value('id');
            if (! $brandId) {
                continue;
            }
            DB::table('outlets')->where('brand_id', $brandId)->where('name', $outlet)
                ->where(fn ($q) => $q->whereNull('address')->orWhere('address', ''))
                ->update(['address' => $address]);
            if ($city) {
                DB::table('outlets')->where('brand_id', $brandId)->where('name', $outlet)->whereNull('city')->update(['city' => $city]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('receipt_guide');
        });
    }
};
