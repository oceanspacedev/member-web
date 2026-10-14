{{-- Sprite ikon landing page. Ikon duotone sama dengan ikon menu aplikasi (mobile/src/components/menu-icon.tsx). --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <defs>
        <path id="p-petal" d="M18.5 18.5H9.25A9.25 9.25 0 0 1 0 9.25A9.25 9.25 0 0 1 9.25 0A9.25 9.25 0 0 1 18.5 9.25Z"/>
    </defs>

    {{-- Logo: empat kelopak --}}
    <symbol id="i-logo" viewBox="0 0 40 40">
        <use href="#p-petal" fill="#C8A464"/>
        <use href="#p-petal" fill="#E6CF9C" transform="rotate(90 20 20)"/>
        <use href="#p-petal" fill="#C8A464" transform="rotate(180 20 20)"/>
        <use href="#p-petal" fill="#E6CF9C" transform="rotate(270 20 20)"/>
    </symbol>
    <symbol id="i-logo-mono" viewBox="0 0 40 40">
        <use href="#p-petal" fill="currentColor"/>
        <use href="#p-petal" fill="currentColor" transform="rotate(90 20 20)"/>
        <use href="#p-petal" fill="currentColor" transform="rotate(180 20 20)"/>
        <use href="#p-petal" fill="currentColor" transform="rotate(270 20 20)"/>
    </symbol>

    {{-- Ikon fitur (kotak 48) --}}
    <symbol id="i-vouchers" viewBox="0 0 48 48">
        <path d="M8 13H40A3 3 0 0 1 43 16V20A4 4 0 0 0 43 28V32A3 3 0 0 1 40 35H8A3 3 0 0 1 5 32V28A4 4 0 0 0 5 20V16A3 3 0 0 1 8 13Z" fill="#C8A464"/>
        <path d="M31 17V31" stroke-dasharray="2 3.2"/>
        <use href="#p-petal" transform="translate(12.5 19.4) scale(0.5)" fill="#18181B" stroke="none"/>
    </symbol>
    <symbol id="i-buy" viewBox="0 0 48 48">
        <path d="M18 20V15A6 6 0 0 1 30 15V20"/>
        <path d="M11 18H37L35 39A3 3 0 0 1 32 42H16A3 3 0 0 1 13 39Z" fill="#C8A464"/>
        <use href="#p-petal" transform="translate(19.4 25) scale(0.5)" fill="#F3E7CB" stroke-width="4.4"/>
    </symbol>
    <symbol id="i-points" viewBox="0 0 48 48">
        <circle cx="30" cy="17" r="10" fill="#EBD9B0"/>
        <circle cx="19" cy="29" r="11.5" fill="#C8A464"/>
        <g stroke="none" fill="#18181B" transform="translate(13.4 23.4) scale(0.28)">
            <use href="#p-petal"/>
            <use href="#p-petal" transform="rotate(90 20 20)"/>
            <use href="#p-petal" transform="rotate(180 20 20)"/>
            <use href="#p-petal" transform="rotate(270 20 20)"/>
        </g>
    </symbol>
    <symbol id="i-promo" viewBox="0 0 48 48">
        <use href="#p-petal" transform="translate(6 6) scale(1.95)" fill="#C8A464" stroke-width="1.13"/>
        <circle cx="17" cy="17" r="3"/>
        <circle cx="28" cy="28" r="3"/>
        <path d="M29.5 14.5 15.5 30.5"/>
    </symbol>
    <symbol id="i-outlet" viewBox="0 0 48 48">
        <path d="M10 20V38A2 2 0 0 0 12 40H36A2 2 0 0 0 38 38V20" fill="#EBD9B0"/>
        <path d="M7 16 10 8H38L41 16A4.25 4.25 0 0 1 32.5 16A4.25 4.25 0 0 1 24 16A4.25 4.25 0 0 1 15.5 16A4.25 4.25 0 0 1 7 16Z" fill="#C8A464"/>
        <path d="M20 40V30A4 4 0 0 1 28 30V40" fill="#C8A464"/>
    </symbol>
    <symbol id="i-brands" viewBox="0 0 48 48">
        <g stroke-width="2.3" transform="translate(5 5) scale(0.95)">
            <use href="#p-petal" fill="#C8A464"/>
            <use href="#p-petal" fill="#EBD9B0" transform="rotate(90 20 20)"/>
            <use href="#p-petal" fill="#C8A464" transform="rotate(180 20 20)"/>
            <use href="#p-petal" fill="#EBD9B0" transform="rotate(270 20 20)"/>
        </g>
    </symbol>
    <symbol id="i-history" viewBox="0 0 48 48">
        <path d="M11 6H35V41L31 38.5 27 41 23 38.5 19 41 15 38.5 11 41Z" fill="#EBD9B0"/>
        <path d="M16 14H30M16 20H30M16 26H23"/>
        <circle cx="33" cy="33" r="7.5" fill="#C8A464"/>
        <path d="M33 29.5V36.5M29.5 33H36.5"/>
    </symbol>
    <symbol id="i-level" viewBox="0 0 48 48">
        <path d="M7 17 15.5 25 24 11 32.5 25 41 17 37.5 35H10.5Z" fill="#C8A464"/>
        <rect x="10.5" y="35" width="27" height="5" rx="1.5" fill="#EBD9B0"/>
        <use href="#p-petal" transform="translate(20.3 23.5) scale(0.4)" fill="#18181B" stroke="none"/>
    </symbol>

    {{-- Ikon garis kecil (kotak 24) --}}
    <symbol id="i-download" viewBox="0 0 24 24">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
        <path d="m7 10 5 5 5-5"/>
        <path d="M12 15V3"/>
    </symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></symbol>
    <symbol id="i-crown" viewBox="0 0 24 24"><path d="m3 8 4.5 4L12 5l4.5 7L21 8l-2 11H5zM5 21h14"/></symbol>
    <symbol id="i-lock" viewBox="0 0 24 24">
        <rect x="3" y="11" width="18" height="11" rx="2"/>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
    </symbol>
    <symbol id="i-chat" viewBox="0 0 24 24"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></symbol>
    <symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></symbol>
</svg>
