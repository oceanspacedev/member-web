@extends('legal.layout')

@section('title', 'Syarat & Ketentuan')
@section('updated', '14 Oktober 2026')

@section('content')
    @php
        $tiers = config('loyalty.tiers');
        $rp = fn (int $n) => 'Rp'.number_format($n, 0, ',', '.');
    @endphp

    <p>
        Syarat ini mengatur pemakaian aplikasi {{ config('app.name') }} (selanjutnya "aplikasi"), program keanggotaan untuk outlet dan brand dalam grup
        {{ config('app.name') }}. Dengan membuat akun atau memakai aplikasi, kamu menyetujui syarat ini dan
        <a href="{{ route('legal.privacy') }}">Kebijakan Privasi</a>.
    </p>

    <h2>1. Akun</h2>
    <ul>
        <li>Kamu masuk dengan nomor WhatsApp yang kamu miliki dan kode OTP. Jaga kerahasiaan kode dan jangan membagikannya kepada siapa pun.</li>
        <li>Satu orang hanya boleh memiliki satu akun. Data yang kamu isi harus benar; tanggal lahir tidak dapat diubah setelah disimpan.</li>
        <li>Kamu bertanggung jawab atas aktivitas di akunmu.</li>
    </ul>

    <h2>2. Poin dan level</h2>
    <ul>
        <li>Poin didapat dari belanja di outlet {{ config('app.name') }} yang tercatat lewat kasir atau foto struk. Nilai 1 poin adalah {{ $rp((int) config('loyalty.point_value')) }}.</li>
        <li>
            Level ditentukan dari total belanja 12 bulan terakhir:
            @foreach ($tiers as $tier)
                {{ $tier['label'] }} ({{ $tier['min_annual_spend'] === 0 ? 'anggota baru' : 'mulai '.$rp($tier['min_annual_spend']) }}, cashback {{ $tier['cashback_percent'] }}%){{ ! $loop->last ? ',' : '.' }}
            @endforeach
            Level dapat naik atau turun mengikuti belanjamu.
        </li>
        <li>Poin bukan uang tunai, tidak dapat dipindahtangankan, dan tidak dapat diuangkan. Poin ditukar dengan potongan atau voucher sesuai ketentuan di aplikasi.</li>
    </ul>

    <h2>3. Foto struk</h2>
    <ul>
        <li>Kirim hanya struk asli dari belanjamu sendiri di outlet {{ config('app.name') }}, dengan nama outlet, nomor struk, tanggal, dan total yang terbaca jelas.</li>
        <li>Struk berlaku maksimal {{ config('loyalty.receipts.max_age_days') }} hari sejak tanggal belanja, dan satu struk hanya dapat dipakai satu kali.</li>
        <li>Batas pengiriman adalah {{ config('loyalty.receipts.daily_limit') }} struk per hari.</li>
        <li>Struk diperiksa otomatis dan, bila meragukan, oleh petugas. Kami dapat menolak struk yang tidak jelas, tidak sesuai, atau sudah pernah dipakai.</li>
    </ul>

    <h2>4. Voucher</h2>
    <ul>
        <li>Voucher dapat diklaim gratis, ditukar dengan poin, atau dibeli, sesuai keterangan pada promo. Setiap voucher memiliki masa berlaku, minimal belanja, dan tempat berlaku yang tertera.</li>
        <li>Satu voucher dipakai untuk satu struk, tidak dapat diuangkan, dan tidak dapat dipindahtangankan. Voucher yang kedaluwarsa atau sudah dipakai tidak dapat dipakai lagi.</li>
        <li>Pembayaran voucher diproses oleh Xendit. Pengembalian dana mengikuti keterangan pada promo dan hukum yang berlaku.</li>
        <li>Kami dapat mengubah atau mengakhiri promo sewaktu-waktu; voucher yang sudah kamu miliki tetap berlaku sampai masa berlakunya berakhir.</li>
    </ul>

    <h2>5. Yang tidak diperbolehkan</h2>
    <p>Kamu tidak boleh memalsukan atau menggandakan struk, memakai akun atau perangkat lunak otomatis untuk memanipulasi poin atau voucher, mengganggu keamanan sistem, atau memakai aplikasi untuk tujuan melawan hukum.</p>

    <h2>6. Penangguhan dan penghapusan akun</h2>
    <p>
        Kami dapat menangguhkan atau menutup akun, serta membatalkan poin dan voucher yang didapat dengan cara yang melanggar syarat ini.
        Kamu dapat menghapus akunmu kapan saja lewat aplikasi (lihat <a href="{{ route('legal.delete-account') }}">hapus akun</a>); poin dan voucher yang belum dipakai akan hangus.
    </p>

    <h2>7. Batasan tanggung jawab</h2>
    <p>
        Aplikasi disediakan sebagaimana adanya. Kami berupaya menjaganya tetap berjalan, tetapi tidak menjamin tanpa gangguan.
        Sejauh diizinkan hukum, kami tidak bertanggung jawab atas kerugian tidak langsung akibat gangguan layanan di luar kendali kami.
    </p>

    <h2>8. Perubahan dan hukum yang berlaku</h2>
    <p>Syarat ini dapat berubah; versi terbaru selalu tersedia di halaman ini dan perubahan penting diberitahukan lewat aplikasi. Syarat ini tunduk pada hukum Republik Indonesia.</p>
@endsection
