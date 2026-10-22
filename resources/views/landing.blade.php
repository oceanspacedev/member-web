@php
    $app = config('app.name');
    $legal = config('loyalty.legal');
    $whatsapp = preg_replace('/\D/', '', (string) $legal['whatsapp']);
    $entity = $legal['entity'] ?: $app;
    $hasContact = filled($legal['email']) || $whatsapp !== '';
    $rp = fn (int $n) => 'Rp'.number_format($n, 0, ',', '.');

    $brands = collect($directory['brands']);
    $outlets = (int) $directory['outlets'];
    $birthday = collect($tiers)->filter(fn ($tier) => in_array('Voucher ulang tahun', $tier['perks'], true))->pluck('label')->join(' dan ');

    $title = $app.': satu kartu member untuk semua brand';
    $description = 'Foto struk belanja, poin langsung masuk, tukar jadi voucher, dan naik level untuk cashback lebih besar. Satu kartu member berlaku di semua brand grup '.$app.'. Gratis untuk iPhone dan Android.';
    $ogImage = asset('images/og-ocean-space.png');
    $cssVersion = @filemtime(public_path('css/landing.css')) ?: 1;

    $faq = [
        ['Apakah '.$app.' gratis?', 'Ya. Aplikasinya gratis diunduh dan dipakai. Kamu hanya membayar belanjamu di outlet, atau voucher berbayar bila kamu memilih membelinya.'],
        ['Bagaimana cara mendapat poin?', 'Belanja di outlet '.$app.', lalu foto struknya lewat tab Foto Struk. Sistem membaca struk secara otomatis dan poin langsung masuk; struk yang meragukan diperiksa petugas. Struk berlaku maksimal '.$receipts['max_age_days'].' hari sejak belanja, dengan batas '.$receipts['daily_limit'].' struk per hari.'],
        ['Berapa nilai satu poin?', '1 poin bernilai '.$rp($pointValue).'. Poin bisa ditukar dengan voucher, dan cashback poin makin besar saat levelmu naik.'],
        ['Kenapa masuk lewat WhatsApp?', 'Supaya kamu tidak perlu mengingat password. Cukup masukkan nomor, lalu kode OTP dikirim ke WhatsApp nomor itu.'],
        ['Apakah poinku bisa dipakai di brand lain?', 'Bisa. Satu kartu, satu saldo poin, berlaku di seluruh brand grup '.$app.'. Sebagian voucher khusus untuk satu brand atau level tertentu; ketentuannya tertera di tiap voucher.'],
        ['Bagaimana cara menghapus akun?', 'Buka Profil, pilih Akun & data diri, lalu ketuk Hapus akun. Data pribadimu dihapus permanen, sedangkan poin dan voucher yang belum dipakai hangus. Langkah lengkapnya ada di halaman Hapus Akun.'],
    ];

    $structured = [
        '@context' => 'https://schema.org',
        '@graph' => [
            array_filter([
                '@type' => 'MobileApplication',
                'name' => $app,
                'description' => $description,
                'url' => url('/'),
                'image' => $ogImage,
                'operatingSystem' => 'Android, iOS',
                'applicationCategory' => 'LifestyleApplication',
                'inLanguage' => 'id-ID',
                'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'IDR'],
                'installUrl' => $stores['android'] ?? $stores['ios'],
            ]),
            [
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn ($qa) => [
                    '@type' => 'Question',
                    'name' => $qa[0],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]],
                ], $faq),
            ],
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <meta name="theme-color" content="#18181B">
    <link rel="canonical" href="{{ url('/') }}">

    <meta property="og:type" content="website">
    <meta property="og:locale" content="id_ID">
    <meta property="og:site_name" content="{{ $app }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Logo {{ $app }}: satu kartu member untuk semua brand">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    <link rel="preload" href="{{ asset('fonts/plus-jakarta-sans/PlusJakartaSans-latin-wght.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/landing.css') }}?v={{ $cssVersion }}">

    <script type="application/ld+json">{!! json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
</head>
<body>
@include('landing.icons')
<a class="skip" href="#isi">Lewati ke konten</a>

<header class="nav">
    <div class="wrap nav__inner">
        <a class="logo" href="{{ route('landing') }}" aria-label="{{ $app }}, ke awal halaman">
            <svg viewBox="0 0 40 40" aria-hidden="true"><use href="#i-logo"/></svg>
            {{ $app }}
        </a>
        <nav aria-label="Menu utama">
            <ul class="nav__links">
                <li><a href="#fitur">Fitur</a></li>
                <li><a href="#cara-kerja">Cara kerja</a></li>
                <li><a href="#level">Level</a></li>
                <li><a href="#brand">Brand</a></li>
                <li><a href="#faq">FAQ</a></li>
            </ul>
        </nav>
        <a class="btn btn--gold" href="#unduh">Unduh aplikasi</a>
    </div>
</header>

<main id="isi">
    {{-- Hero --}}
    <section class="hero">
        <div class="wrap hero__grid">
            <div class="hero__copy">
                <p class="eyebrow">{{ $app }}</p>
                <h1>Satu kartu member untuk <em>semua brand</em> {{ $app }}</h1>
                <p class="lead">Belanja di brand favoritmu, foto struknya, dan poin langsung masuk. Tukar jadi voucher, naik level, lalu nikmati cashback yang makin besar.</p>

                @include('landing.stores', ['stores' => $stores])
                <p class="hero__note">Gratis untuk iPhone dan Android.</p>

                <dl class="stats">
                    @if ($brands->isNotEmpty())
                        <div class="stat"><dt>Brand</dt><dd>{{ $brands->count() }}</dd></div>
                    @endif
                    @if ($outlets > 0)
                        <div class="stat"><dt>Outlet</dt><dd>{{ number_format($outlets, 0, ',', '.') }}</dd></div>
                    @endif
                    <div class="stat"><dt>Level member</dt><dd>{{ count($tiers) }}</dd></div>
                    <div class="stat"><dt>Nilai 1 poin</dt><dd>{{ $rp($pointValue) }}</dd></div>
                </dl>
            </div>

            <div class="hero__visual">
                <div class="phone phone--back">
                    <div class="phone__screen"><img src="{{ asset('images/landing/voucher.jpg') }}" width="646" height="1400" alt="Voucher dengan kode QR dan barcode yang siap dipindai kasir"></div>
                </div>
                <div class="phone phone--front">
                    <div class="phone__screen"><img src="{{ asset('images/landing/home.jpg') }}" width="646" height="1400" fetchpriority="high" alt="Beranda aplikasi {{ $app }}: saldo poin, progres level, dan menu member"></div>
                </div>
                <div class="float float--points">
                    <svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><use href="#i-check"/></svg>
                    <span>+250 poin<small>dari foto struk</small></span>
                </div>
                <div class="float float--level">
                    <svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><use href="#i-crown"/></svg>
                    <span>Naik ke level Gold<small>cashback {{ $tiers['gold']['cashback_percent'] ?? '' }}%</small></span>
                </div>
            </div>
        </div>

        @if ($brands->isNotEmpty())
            <div class="wrap hero__brands">
                Berlaku di
                @foreach ($brands as $brand)
                    <span>{{ $brand['name'] }}</span>@if (! $loop->last)<i>·</i>@endif
                @endforeach
            </div>
        @endif
    </section>

    {{-- Fitur --}}
    <section class="section" id="fitur">
        <div class="wrap">
            <div class="head">
                <span class="eyebrow">Fitur</span>
                <h2>Semua yang kamu butuhkan sebagai member</h2>
                <p>Dirancang supaya mengumpulkan dan memakai poin semudah memotret struk.</p>
            </div>
            <div class="features">
                <article class="feature">
                    <div class="feature__ico"><svg class="ico" viewBox="0 0 48 48" aria-hidden="true"><use href="#i-history"/></svg></div>
                    <h3>Poin dari foto struk</h3>
                    <p>Selesai belanja, foto struknya. Poin masuk otomatis setelah diperiksa, tanpa kartu fisik dan tanpa antre di kasir.</p>
                </article>
                <article class="feature">
                    <div class="feature__ico"><svg class="ico" viewBox="0 0 48 48" aria-hidden="true"><use href="#i-vouchers"/></svg></div>
                    <h3>Voucher yang bisa kamu pakai</h3>
                    <p>Klaim gratis, tukar dengan poin, atau beli. Tunjukkan QR ke kasir dan tagihan langsung terpotong.</p>
                </article>
                <article class="feature">
                    <div class="feature__ico"><svg class="ico" viewBox="0 0 48 48" aria-hidden="true"><use href="#i-level"/></svg></div>
                    <h3>Level dan cashback</h3>
                    <p>Naik otomatis dari {{ collect($tiers)->first()['label'] }} sampai {{ collect($tiers)->last()['label'] }}. Makin tinggi levelmu, makin besar cashback poin di setiap belanja.</p>
                </article>
                <article class="feature">
                    <div class="feature__ico"><svg class="ico" viewBox="0 0 48 48" aria-hidden="true"><use href="#i-brands"/></svg></div>
                    <h3>Satu kartu, banyak brand</h3>
                    <p>Kuliner, fast food, retail &amp; emas, sampai sports. Satu saldo poin berlaku di semuanya.</p>
                </article>
                <article class="feature">
                    <div class="feature__ico"><svg class="ico" viewBox="0 0 48 48" aria-hidden="true"><use href="#i-promo"/></svg></div>
                    <h3>Promo{{ $birthday ? ' dan hadiah ulang tahun' : ' pilihan' }}</h3>
                    <p>Promo terbaru dari tiap brand{{ $birthday ? ', plus voucher ulang tahun untuk member level '.$birthday : '' }}. Semuanya terkumpul di satu tempat.</p>
                </article>
                <article class="feature">
                    <div class="feature__ico"><svg class="ico" viewBox="0 0 48 48" aria-hidden="true"><use href="#i-outlet"/></svg></div>
                    <h3>Outlet terdekat</h3>
                    <p>Cari outlet, jam buka, dan arah jalan. Diurutkan dari yang terdekat bila kamu mengizinkan lokasi.</p>
                </article>
            </div>
        </div>
    </section>

    {{-- Galeri --}}
    <section class="section section--dark" id="aplikasi">
        <div class="wrap">
            <div class="head">
                <span class="eyebrow">Intip aplikasinya</span>
                <h2>Sederhana dipakai, nyaman dilihat</h2>
                <p>Tampilan hitam dan emas yang bersih, dengan semua yang penting satu ketukan dari beranda.</p>
            </div>
            <div class="gallery">
                @foreach ([
                    ['home', 'Beranda', 'Saldo poin, progres level, dan semua menu member dalam satu layar.', 'Beranda aplikasi: saldo 10.000 poin, progres menuju level Gold, dan delapan menu member'],
                    ['promo', 'Promo', 'Promo dan voucher dari semua brand, bisa disaring per kategori.', 'Halaman promo berisi kartu promo bergambar dengan filter kategori'],
                    ['voucher', 'Voucher', 'QR dan barcode siap dipindai kasir, atau cukup sebutkan kodenya.', 'Halaman voucher dengan kode QR, barcode, dan kode yang bisa disalin'],
                    ['level', 'Level member', 'Lihat benefit tiap level dan berapa lagi menuju level berikutnya.', 'Halaman level member: kartu Silver, Gold, dan Platinum beserta benefitnya'],
                ] as [$file, $name, $text, $alt])
                    <figure class="shot">
                        <div class="phone"><div class="phone__screen"><img src="{{ asset('images/landing/'.$file.'.jpg') }}" width="646" height="1400" loading="lazy" decoding="async" alt="{{ $alt }}"></div></div>
                        <figcaption><strong>{{ $name }}</strong><span>{{ $text }}</span></figcaption>
                    </figure>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Cara kerja --}}
    <section class="section section--white" id="cara-kerja">
        <div class="wrap">
            <div class="head">
                <span class="eyebrow">Cara kerja</span>
                <h2>Mulai dalam tiga langkah</h2>
                <p>Dari mendaftar sampai poin pertamamu, tidak sampai lima menit.</p>
            </div>
            <div class="steps">
                <article class="step">
                    <div class="step__ico"><svg class="ico" viewBox="0 0 48 48" aria-hidden="true"><use href="#i-brands"/></svg></div>
                    <h3>Masuk dengan WhatsApp</h3>
                    <p>Masukkan nomor, terima kode OTP, lalu lengkapi nama dan tanggal lahir. Tanpa password.</p>
                </article>
                <article class="step">
                    <div class="step__ico"><svg class="ico" viewBox="0 0 48 48" aria-hidden="true"><use href="#i-history"/></svg></div>
                    <h3>Belanja, lalu foto struknya</h3>
                    <p>Foto struk dari outlet {{ $app }} mana pun. Kami periksa otomatis dan poin langsung masuk ke akunmu.</p>
                </article>
                <article class="step">
                    <div class="step__ico"><svg class="ico" viewBox="0 0 48 48" aria-hidden="true"><use href="#i-vouchers"/></svg></div>
                    <h3>Tukar dan nikmati</h3>
                    <p>Pakai poin atau klaim voucher, tunjukkan QR ke kasir, dan naik level untuk cashback yang lebih besar.</p>
                </article>
            </div>
        </div>
    </section>

    {{-- Level --}}
    <section class="section" id="level">
        <div class="wrap">
            <div class="head">
                <span class="eyebrow">Level member</span>
                <h2>Makin sering belanja, makin besar cashback</h2>
                <p>Level dihitung dari total belanja 12 bulan terakhir dan naik otomatis. Cashback diberikan dalam bentuk poin.</p>
            </div>
            <div class="tiers">
                @foreach ($tiers as $key => $tier)
                    <article class="tier tier--{{ $key }}">
                        <svg class="tier__mark" viewBox="0 0 40 40" aria-hidden="true" style="color:#fff"><use href="#i-logo-mono"/></svg>
                        <div class="tier__brand"><svg viewBox="0 0 40 40" aria-hidden="true"><use href="#i-logo"/></svg>{{ $app }}</div>
                        <h3>{{ mb_strtoupper($tier['label']) }}</h3>
                        <p class="tier__req">{{ $tier['min_annual_spend'] === 0 ? 'Anggota baru' : 'Belanja ≥ '.$rp($tier['min_annual_spend']).' / 12 bulan' }}</p>
                        <ul>
                            <li><svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><use href="#i-check"/></svg>Cashback poin {{ $tier['cashback_percent'] }}%</li>
                            @foreach ($tier['perks'] as $perk)
                                <li><svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><use href="#i-check"/></svg>{{ $perk }}</li>
                            @endforeach
                        </ul>
                        <p class="tier__eg">Belanja {{ $rp(100000) }} &rarr; <strong>{{ number_format(intdiv(100000 * $tier['cashback_percent'], 100 * max($pointValue, 1)), 0, ',', '.') }} poin</strong></p>
                    </article>
                @endforeach
            </div>
            <p class="note">1 poin bernilai {{ $rp($pointValue) }} dan bisa ditukar dengan voucher di semua brand grup.</p>
        </div>
    </section>

    {{-- Brand --}}
    @if ($brands->isNotEmpty())
        <section class="section section--white" id="brand">
            <div class="wrap">
                <div class="head">
                    <span class="eyebrow">Brand grup</span>
                    <h2>{{ $brands->count() }} brand, satu kartu member</h2>
                    <p>Poin yang kamu kumpulkan di satu brand bisa ditukar di brand lain.</p>
                </div>
                <div class="brandcats">
                    @foreach ($categories as $key => $label)
                        @php
                            $group = $brands->where('category', $key);
                        @endphp
                        @continue($group->isEmpty())
                        <div class="brandcat">
                            <h3>{{ $label }}</h3>
                            <ul class="chips">
                                @foreach ($group as $brand)
                                    @php
                                        $logo = \App\Support\Media::url($brand['logo_path']);
                                        $color = preg_match('/^#[0-9a-f]{3,8}$/i', (string) $brand['color']) ? $brand['color'] : '#18181B';
                                        $initials = collect(preg_split('/\s+/', trim($brand['name'])))->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('');
                                    @endphp
                                    <li class="chip">
                                        @if ($logo)
                                            <img src="{{ $logo }}" alt="" width="32" height="32" loading="lazy">
                                        @else
                                            <i style="background:{{ $color }}" aria-hidden="true">{{ $initials }}</i>
                                        @endif
                                        {{ $brand['name'] }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Aman --}}
    <section class="section" id="aman">
        <div class="wrap">
            <div class="head">
                <span class="eyebrow">Aman dan transparan</span>
                <h2>Datamu tetap di tanganmu</h2>
                <p>Kami hanya meminta data yang diperlukan, dan kamu bisa menghapusnya kapan saja.</p>
            </div>
            <div class="trust">
                <article>
                    <div class="trust__ico"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#i-chat"/></svg></div>
                    <h3>Tanpa password</h3>
                    <p>Masuk dengan kode OTP lewat WhatsApp. Tidak ada kata sandi yang bisa lupa atau bocor.</p>
                </article>
                <article>
                    <div class="trust__ico"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#i-lock"/></svg></div>
                    <h3>Dijaga dengan baik</h3>
                    <p>Koneksi terenkripsi, token tersimpan aman di HP, dan foto struk hanya bisa dilihat petugas yang berwenang.</p>
                </article>
                <article>
                    <div class="trust__ico"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#i-trash"/></svg></div>
                    <h3>Kendali ada padamu</h3>
                    <p>Hapus akun kapan saja dari aplikasi. Data pribadimu dihapus permanen.</p>
                </article>
            </div>
            <p class="links">
                <a href="{{ route('legal.privacy') }}">Kebijakan Privasi</a>
                <a href="{{ route('legal.terms') }}">Syarat &amp; Ketentuan</a>
                <a href="{{ route('legal.delete-account') }}">Cara hapus akun</a>
            </p>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="section section--white" id="faq">
        <div class="wrap">
            <div class="head">
                <span class="eyebrow">FAQ</span>
                <h2>Pertanyaan yang sering diajukan</h2>
            </div>
            <div class="faq">
                @foreach ($faq as [$question, $answer])
                    <details class="qa">
                        <summary>{{ $question }}</summary>
                        <p>{{ $answer }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Unduh --}}
    <section class="cta" id="unduh">
        <div class="wrap cta__box">
            <div>
                <span class="eyebrow">Gratis</span>
                <h2>Mulai kumpulkan poin hari ini</h2>
                <p class="cta__lead">Unduh {{ $app }}, masuk dengan WhatsApp, dan foto struk pertamamu. Poin pertama bisa masuk dalam hitungan menit.</p>
                @include('landing.stores', ['stores' => $stores])
            </div>
            <div class="cta__art">
                <div class="phone">
                    <div class="phone__screen"><img src="{{ asset('images/landing/promo.jpg') }}" width="646" height="1400" loading="lazy" decoding="async" alt="Halaman promo aplikasi {{ $app }}"></div>
                </div>
            </div>
        </div>
    </section>
</main>

<footer class="footer">
    <div class="wrap">
        <div class="footer__grid{{ $hasContact ? '' : ' footer__grid--3' }}">
            <div>
                <a class="logo" href="{{ route('landing') }}"><svg viewBox="0 0 40 40" aria-hidden="true"><use href="#i-logo"/></svg>{{ $app }}</a>
                <p>Aplikasi member lintas brand untuk seluruh unit bisnis grup {{ $app }}. Satu kartu, satu saldo poin.</p>
            </div>
            <div>
                <h4>Aplikasi</h4>
                <ul>
                    <li><a href="#fitur">Fitur</a></li>
                    <li><a href="#cara-kerja">Cara kerja</a></li>
                    <li><a href="#level">Level member</a></li>
                    <li><a href="#faq">FAQ</a></li>
                </ul>
            </div>
            <div>
                <h4>Informasi</h4>
                <ul>
                    <li><a href="{{ route('legal.privacy') }}">Kebijakan Privasi</a></li>
                    <li><a href="{{ route('legal.terms') }}">Syarat &amp; Ketentuan</a></li>
                    <li><a href="{{ route('legal.delete-account') }}">Hapus Akun</a></li>
                </ul>
            </div>
            @if ($hasContact)
                <div>
                    <h4>Kontak</h4>
                    <ul>
                        @if ($legal['email'])
                            <li><a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a></li>
                        @endif
                        @if ($whatsapp)
                            <li><a href="https://wa.me/{{ $whatsapp }}" rel="noopener">WhatsApp +{{ $whatsapp }}</a></li>
                        @endif
                    </ul>
                </div>
            @endif
        </div>
        <div class="footer__bottom">
            <span>&copy; {{ date('Y') }} {{ $entity }}</span>
            <span>Dibuat untuk member {{ $app }}.</span>
        </div>
    </div>
</footer>
</body>
</html>
