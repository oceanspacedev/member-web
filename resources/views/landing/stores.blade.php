{{--
    Tombol unduh. Tautan toko diisi lewat PLAY_STORE_URL / APP_STORE_URL; bila belum diisi,
    tombol tampil sebagai "Segera hadir" (bukan tautan mati).
    Tombol ini bergaya sendiri; bila ingin memakai badge resmi Google/Apple, ganti isi <a> di sini dengan gambar badge.
--}}
@php
    $items = [
        ['key' => 'android', 'verb' => 'Dapatkan di', 'name' => 'Google Play'],
        ['key' => 'ios', 'verb' => 'Unduh di', 'name' => 'App Store'],
    ];
@endphp
<div class="stores">
    @foreach ($items as $item)
        @if ($stores[$item['key']])
            <a class="store" href="{{ $stores[$item['key']] }}" target="_blank" rel="noopener" data-store="{{ $item['key'] }}">
                <span class="store__ico"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#i-download"/></svg></span>
                <span><small>{{ $item['verb'] }}</small><strong>{{ $item['name'] }}</strong></span>
            </a>
        @else
            <span class="store is-soon" role="img" aria-label="{{ $item['name'] }}: segera hadir">
                <span class="store__ico"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#i-download"/></svg></span>
                <span><small>Segera hadir di</small><strong>{{ $item['name'] }}</strong></span>
            </span>
        @endif
    @endforeach
</div>
