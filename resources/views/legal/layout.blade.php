@php
    $legal = config('loyalty.legal');
    $entity = $legal['entity'] ?: config('app.name');
    $whatsapp = preg_replace('/\D/', '', (string) $legal['whatsapp']);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <style>
        :root { --ink: #18181b; --ink-soft: #3f3f46; --muted: #67676f; --line: #e7e5e4; --ground: #f7f7f5; --gold: #c8a464; --gold-text: #7a5f2a; --gold-soft: #f4eedf; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--ground); color: var(--ink-soft); font: 16px/1.7 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; -webkit-text-size-adjust: 100%; }
        header { background: var(--ink); }
        .bar { max-width: 760px; margin: 0 auto; padding: 18px 20px; display: flex; align-items: center; gap: 12px; color: #fff; }
        .bar svg { width: 30px; height: 30px; flex: none; }
        .bar strong { font-size: 17px; letter-spacing: -0.2px; }
        .bar span { color: var(--gold); font-size: 11px; font-weight: 700; letter-spacing: 3px; text-transform: uppercase; }
        main { max-width: 760px; margin: 0 auto; padding: 32px 20px 24px; }
        h1 { margin: 0 0 4px; color: var(--ink); font-size: 30px; line-height: 1.2; letter-spacing: -0.6px; }
        h2 { margin: 32px 0 8px; color: var(--ink); font-size: 19px; line-height: 1.3; }
        p, ul, ol { margin: 0 0 14px; }
        li { margin-bottom: 6px; }
        a { color: var(--gold-text); font-weight: 600; }
        .meta { color: var(--muted); font-size: 14px; margin-bottom: 24px; }
        .note { background: var(--gold-soft); border: 1px solid #e4d6b8; border-radius: 14px; padding: 14px 16px; color: var(--ink); }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 16px 18px; margin-bottom: 14px; }
        .card p:last-child, .card ul:last-child { margin-bottom: 0; }
        footer { max-width: 760px; margin: 0 auto; padding: 8px 20px 48px; color: var(--muted); font-size: 14px; }
        footer nav { display: flex; flex-wrap: wrap; gap: 6px 18px; margin-bottom: 10px; }
    </style>
</head>
<body>
<header>
    <div class="bar">
        <svg viewBox="0 0 40 40" aria-hidden="true">
            <path fill="#C8A464" d="M18.5 18.5H9.25A9.25 9.25 0 0 1 0 9.25A9.25 9.25 0 0 1 9.25 0A9.25 9.25 0 0 1 18.5 9.25Z"/>
            <path fill="#E6CF9C" transform="rotate(90 20 20)" d="M18.5 18.5H9.25A9.25 9.25 0 0 1 0 9.25A9.25 9.25 0 0 1 9.25 0A9.25 9.25 0 0 1 18.5 9.25Z"/>
            <path fill="#C8A464" transform="rotate(180 20 20)" d="M18.5 18.5H9.25A9.25 9.25 0 0 1 0 9.25A9.25 9.25 0 0 1 9.25 0A9.25 9.25 0 0 1 18.5 9.25Z"/>
            <path fill="#E6CF9C" transform="rotate(270 20 20)" d="M18.5 18.5H9.25A9.25 9.25 0 0 1 0 9.25A9.25 9.25 0 0 1 9.25 0A9.25 9.25 0 0 1 18.5 9.25Z"/>
        </svg>
        <div><strong>{{ config('app.name') }}</strong><br><span>Member Club</span></div>
    </div>
</header>

<main>
    <h1>@yield('title')</h1>
    <p class="meta">Terakhir diperbarui: @yield('updated')</p>
    @yield('content')

    <h2>Hubungi kami</h2>
    @if ($legal['email'] || $whatsapp)
        <ul>
            @if ($legal['email'])
                <li>Email: <a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a></li>
            @endif
            @if ($whatsapp)
                <li>WhatsApp: <a href="https://wa.me/{{ $whatsapp }}">+{{ $whatsapp }}</a></li>
            @endif
        </ul>
    @else
        <p>Kontak resmi kami tercantum di halaman aplikasi {{ config('app.name') }} di App Store dan Google Play.</p>
    @endif
</main>

<footer>
    <nav>
        <a href="{{ route('legal.privacy') }}">Kebijakan Privasi</a>
        <a href="{{ route('legal.terms') }}">Syarat &amp; Ketentuan</a>
        <a href="{{ route('legal.delete-account') }}">Hapus Akun</a>
    </nav>
    &copy; {{ date('Y') }} {{ $entity }}
</footer>
</body>
</html>
