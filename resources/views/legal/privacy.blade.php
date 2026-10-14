@extends('legal.layout')

@section('title', 'Kebijakan Privasi')
@section('updated', '14 Oktober 2026')

@section('content')
    <p>
        Kebijakan ini menjelaskan data apa yang dikumpulkan aplikasi {{ config('app.name') }} (selanjutnya "aplikasi"), untuk apa data itu dipakai,
        dan pilihan yang kamu miliki. Aplikasi dikelola oleh {{ config('loyalty.legal.entity') ?: config('app.name') }} ("kami").
        Dengan memakai aplikasi, kamu menyetujui kebijakan ini.
    </p>

    <h2>1. Data yang kami kumpulkan</h2>
    <div class="card">
        <p><strong>Data akun.</strong> Nomor WhatsApp (untuk masuk), nama, tanggal lahir (untuk hadiah ulang tahun), serta email dan foto profil bila kamu mengisinya.</p>
        <p><strong>Foto struk belanja.</strong> Foto struk yang kamu kirim untuk mendapat poin, termasuk data yang tertera di dalamnya (nama outlet, nomor struk, tanggal, dan total belanja).</p>
        <p><strong>Aktivitas member.</strong> Saldo dan riwayat poin, voucher yang kamu ambil atau beli, level keanggotaan, dan waktu terakhir kamu memakai aplikasi.</p>
        <p><strong>Lokasi perkiraan (opsional).</strong> Hanya saat aplikasi dipakai dan hanya bila kamu mengizinkan, untuk menampilkan outlet terdekat. Kami tidak melacak lokasimu di latar belakang.</p>
        <p><strong>Data perangkat.</strong> Jenis perangkat (Android atau iOS) yang tercatat saat masuk, dan catatan teknis keamanan seperti percobaan masuk yang gagal.</p>
        <p><strong>Pembayaran voucher.</strong> Pembayaran diproses oleh Xendit. Kami tidak menyimpan nomor kartu atau rekening; kami hanya menerima status dan jumlah pembayaran.</p>
    </div>

    <h2>2. Untuk apa data dipakai</h2>
    <ul>
        <li>Membuat dan mengamankan akun, termasuk mengirim kode OTP ke WhatsApp.</li>
        <li>Menghitung poin, level, cashback, dan menyediakan voucher serta hadiah ulang tahun.</li>
        <li>Memeriksa foto struk (otomatis, lalu oleh petugas bila meragukan) dan mencegah kecurangan.</li>
        <li>Menampilkan outlet terdekat dan promo yang relevan.</li>
        <li>Memproses pembayaran voucher dan memenuhi kewajiban pembukuan serta hukum.</li>
        <li>Memperbaiki layanan dan menjawab pertanyaanmu.</li>
    </ul>
    <p>Kami tidak menjual datamu dan tidak memakainya untuk iklan pihak ketiga.</p>

    <h2>3. Dengan siapa data dibagikan</h2>
    <p>Hanya dengan pihak yang diperlukan untuk menjalankan layanan:</p>
    <ul>
        <li><strong>Penyedia pengiriman WhatsApp</strong> untuk mengirim kode OTP ke nomormu.</li>
        <li><strong>Layanan pembacaan struk otomatis (Google Gemini)</strong>: gambar struk yang kamu kirim dibaca untuk mengambil nama outlet, tanggal, dan total belanja.</li>
        <li><strong>Xendit</strong> untuk memproses pembayaran voucher.</li>
        <li><strong>Penyedia hosting dan infrastruktur</strong> tempat data disimpan.</li>
        <li><strong>Outlet dan brand dalam grup {{ config('app.name') }}</strong>, sebatas yang dibutuhkan kasir untuk memvalidasi voucher atau poinmu.</li>
        <li><strong>Pihak berwenang</strong> bila diwajibkan oleh hukum.</li>
    </ul>

    <h2>4. Penyimpanan dan keamanan</h2>
    <p>
        Data dikirim lewat koneksi terenkripsi (HTTPS). Token masuk disimpan di penyimpanan aman perangkat (Keychain di iOS, Keystore di Android).
        Foto struk disimpan secara privat dan hanya dapat dilihat oleh admin yang berwenang untuk memeriksa klaim poin.
        Tidak ada sistem yang sepenuhnya kebal, tetapi kami membatasi akses dan memantau penyalahgunaan.
    </p>

    <h2>5. Berapa lama data disimpan</h2>
    <p>
        Selama akunmu aktif. Bila kamu menghapus akun, data pribadimu (nama, nomor WhatsApp, email, tanggal lahir, foto profil, dan lokasi) dihapus permanen
        dan semua sesi masuk diputus. Catatan transaksi seperti riwayat poin, voucher, dan pembayaran tetap disimpan tanpa identitas
        untuk pembukuan dan pencegahan kecurangan.
    </p>

    <h2>6. Hak dan pilihanmu</h2>
    <ul>
        <li><strong>Melihat dan memperbaiki</strong> data akun lewat menu Profil &rsaquo; Akun &amp; data diri, atau hubungi kami untuk perubahan yang tidak bisa dilakukan di aplikasi.</li>
        <li><strong>Menghapus akun</strong> kapan saja lewat Profil &rsaquo; Akun &amp; data diri &rsaquo; Hapus akun. Caranya dijelaskan di <a href="{{ route('legal.delete-account') }}">halaman hapus akun</a>.</li>
        <li><strong>Mencabut izin lokasi, kamera, atau galeri</strong> kapan saja di pengaturan HP. Aplikasi tetap bisa dipakai tanpa lokasi.</li>
        <li><strong>Mengajukan keberatan atau pertanyaan</strong> tentang pemrosesan datamu melalui kontak di bawah.</li>
    </ul>

    <h2>7. Anak-anak</h2>
    <p>Aplikasi tidak ditujukan untuk anak-anak. Kami tidak sengaja mengumpulkan data anak tanpa persetujuan orang tua atau walinya; bila ini terjadi, hubungi kami dan data tersebut akan dihapus.</p>

    <h2>8. Perubahan kebijakan</h2>
    <p>Bila kebijakan ini berubah secara berarti, kami akan memberi tahu lewat aplikasi. Tanggal "terakhir diperbarui" di atas selalu menunjukkan versi terbaru.</p>
@endsection
