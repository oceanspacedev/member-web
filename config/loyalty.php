<?php

return [

    /*
    | Nilai 1 poin dalam Rupiah. 100 poin = Rp1.000.
    */
    'point_value' => (int) env('LOYALTY_POINT_VALUE', 10),

    /*
    | Tier dihitung dari total belanja 12 bulan terakhir. Cashback diberikan
    | dalam bentuk poin: belanja x cashback_percent / point_value.
    */
    'tiers' => [
        // perks = benefit tambahan sesuai dokumen rancangan (ditampilkan di kartu tier aplikasi).
        'silver' => ['label' => 'Silver', 'min_annual_spend' => 0, 'cashback_percent' => 2, 'perks' => []],
        'gold' => ['label' => 'Gold', 'min_annual_spend' => 3_000_000, 'cashback_percent' => 5, 'perks' => ['Voucher ulang tahun']],
        'platinum' => ['label' => 'Platinum', 'min_annual_spend' => 10_000_000, 'cashback_percent' => 8, 'perks' => ['Voucher ulang tahun', 'Promo & event eksklusif']],
    ],

    /*
    | Zona waktu jam operasional outlet (data tetap disimpan dalam UTC).
    */
    'timezone' => env('LOYALTY_TIMEZONE', 'Asia/Jakarta'),

    'brand_categories' => [
        'culinary' => 'Kuliner & Resto',
        'fastfood' => 'Fast Food & Snacks',
        'retail' => 'Retail & Perhiasan',
        'sports' => 'Sports & Lifestyle',
    ],

    /*
    | Masa berlaku QR member (detik). QR berganti terus sehingga screenshot
    | lama tidak bisa dipakai.
    */
    'qr_ttl' => (int) env('LOYALTY_QR_TTL', 120),

    /*
    | Poin dari foto struk (outlet dengan POS pihak ketiga). Sistem yang memeriksa: foto dibaca
    | otomatis (Gemini), dicek dengan aturan di bawah, lalu poin langsung masuk bila lolos.
    | Hanya struk yang meragukan yang masuk antrean admin, dengan data yang sudah terisi.
    */
    'receipts' => [
        'max_age_days' => (int) env('LOYALTY_RECEIPT_MAX_AGE_DAYS', 7),
        'daily_limit' => (int) env('LOYALTY_RECEIPT_DAILY_LIMIT', 5),

        // gemini = baca otomatis dengan Gemini (butuh GEMINI_API_KEY); none = semua ke admin.
        'reader' => env('LOYALTY_RECEIPT_READER', 'gemini'),
        'model' => env('LOYALTY_RECEIPT_MODEL', 'gemini-3.8-flash'),
        'gemini_key' => env('GEMINI_API_KEY'),

        // Di atas batas ini selalu diperiksa admin, sekecil apa pun kecurigaannya.
        'auto_approve_max_amount' => (int) env('LOYALTY_RECEIPT_AUTO_MAX_AMOUNT', 2_000_000),
        // Keyakinan minimum hasil baca agar boleh disetujui tanpa admin (0-1).
        'min_confidence' => (float) env('LOYALTY_RECEIPT_MIN_CONFIDENCE', 0.85),
        // Struk disetujui otomatis per member per outlet per hari; lebih dari ini diperiksa admin.
        'auto_per_outlet_per_day' => (int) env('LOYALTY_RECEIPT_AUTO_PER_OUTLET_DAY', 2),
        // true = diperiksa lewat antrean (Horizon/worker); false = langsung setelah respons dikirim.
        'queue' => (bool) env('LOYALTY_RECEIPT_QUEUE', false),
    ],

    'otp' => [
        'length' => 6,
        'ttl' => 300,
        'max_attempts' => 5,
        'resend_cooldown' => 60,
    ],

    // Beli voucher dengan uang. xendit = Payment Session (halaman bayar Xendit: QRIS, VA, e-wallet).
    // Pakai secret key Test Mode (xnd_development_…) selama uji coba; tidak ada uang sungguhan yang berpindah.
    'payment' => [
        'driver' => env('PAYMENT_DRIVER', 'xendit'),
        'order_ttl' => 30, // menit sebelum tagihan kedaluwarsa
        'xendit' => [
            'url' => env('XENDIT_URL', 'https://api.xendit.co'),
            'secret_key' => env('XENDIT_SECRET_KEY'),
            'callback_token' => env('XENDIT_CALLBACK_TOKEN'),
            // Opsional, harus HTTPS: halaman tujuan setelah bayar (mis. https://domain-kamu/bayar-selesai).
            'return_url' => env('XENDIT_RETURN_URL'),
            'timeout' => 15,
        ],
    ],

    'whatsapp' => [
        // waghub = dikirim lewat WAGHub; log = hanya ditulis ke storage/logs (development/testing)
        'driver' => env('WHATSAPP_DRIVER', 'log'),

        'waghub' => [
            'url' => env('WAG_URL', 'https://waghub.mekayastudio.com'),
            'token' => env('WAG_TOKEN', ''),
            'timeout' => (int) env('WAG_TIMEOUT', 10),
        ],

        // Hanya di APP_ENV=local: nomor akun demo seeder tidak dikirimi WhatsApp sungguhan.
        'demo_numbers' => array_values(array_filter(explode(',', (string) env('WHATSAPP_DEMO_NUMBERS', '6281200000001,6281200000002,6281200000003')))),
    ],

    /*
    | Akun admin awal panel web (dibuat/diperbarui oleh seeder). Bisa masuk lewat
    | email + password ini, atau WhatsApp OTP ke nomor admin demo.
    */
    'admin' => [
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'google_client_ids' => array_values(array_filter(explode(',', (string) env('GOOGLE_CLIENT_IDS', '')))),

    /*
    | Tautan unduh aplikasi untuk landing page (/) dan tautan pendek /unduh. Isi setelah aplikasi tayang:
    |   Play Store: https://play.google.com/store/apps/details?id=id.co.oceanspace.member
    |   App Store : https://apps.apple.com/id/app/ocean-space/id<ID dari App Store Connect>
    | Kosong (atau bukan https) = tombolnya tampil sebagai "Segera hadir".
    */
    'stores' => [
        'android' => env('PLAY_STORE_URL'),
        'ios' => env('APP_STORE_URL'),
    ],

    /*
    | Identitas & kontak yang tampil di halaman hukum publik (/kebijakan-privasi, /syarat-ketentuan,
    | /hapus-akun) — tautan wajib di App Store dan Play Store. Isi sebelum rilis.
    */
    'legal' => [
        'entity' => env('LEGAL_ENTITY'),
        'email' => env('SUPPORT_EMAIL'),
        'whatsapp' => env('SUPPORT_WHATSAPP'),
    ],

];
