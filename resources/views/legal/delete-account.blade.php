@extends('legal.layout')

@section('title', 'Hapus Akun')
@section('updated', '14 Oktober 2026')

@section('content')
    <p>Kamu bisa menghapus akun {{ config('app.name') }} dan datamu kapan saja, langsung dari aplikasi.</p>

    <h2>Cara menghapus akun</h2>
    <ol>
        <li>Buka aplikasi {{ config('app.name') }} dan masuk ke akunmu.</li>
        <li>Buka tab <strong>Profil</strong>, lalu pilih <strong>Akun &amp; data diri</strong>.</li>
        <li>Ketuk <strong>Hapus akun</strong>.</li>
        <li>Baca ringkasannya, ketik <strong>HAPUS</strong> untuk konfirmasi, lalu ketuk <strong>Hapus akun</strong>.</li>
    </ol>
    <p class="note">Akun kasir dan admin dihapus oleh admin {{ config('app.name') }}, bukan lewat aplikasi.</p>

    <h2>Apa yang terjadi setelah dihapus</h2>
    <div class="card">
        <p><strong>Dihapus permanen:</strong> nama, nomor WhatsApp, email, tanggal lahir, foto profil, dan data lokasi. Semua sesi masuk di semua perangkat diputus.</p>
        <p><strong>Hangus:</strong> saldo poin dan voucher yang belum dipakai.</p>
        <p><strong>Tetap tersimpan tanpa identitas:</strong> catatan transaksi seperti riwayat poin, voucher, dan pembayaran, untuk pembukuan dan pencegahan kecurangan.</p>
        <p>Penghapusan tidak dapat dibatalkan. Kamu tetap bisa mendaftar lagi kapan saja dengan nomor yang sama sebagai akun baru.</p>
    </div>

    <h2>Tidak bisa membuka aplikasi?</h2>
    <p>Hubungi kami lewat kontak di bawah dari nomor WhatsApp yang terdaftar, dan kami akan membantu menghapus akunmu.</p>
@endsection
