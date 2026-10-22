<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Outlet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Throwable;

class LandingController extends Controller
{
    /**
     * Landing page publik: apa itu Space Club, cara kerja, level, brand, dan tautan unduh.
     */
    public function show(): View
    {
        return view('landing', [
            'stores' => $this->stores(),
            'directory' => $this->directory(),
            'tiers' => config('loyalty.tiers'),
            'pointValue' => (int) config('loyalty.point_value'),
            'receipts' => config('loyalty.receipts'),
            'categories' => config('loyalty.brand_categories'),
        ]);
    }

    /**
     * Tautan pendek untuk QR, poster, dan bio media sosial: iPhone ke App Store, Android ke Play Store,
     * sisanya (atau toko belum tayang) ke bagian unduh di landing page.
     */
    public function download(Request $request): RedirectResponse
    {
        $stores = $this->stores();
        $agent = (string) $request->userAgent();

        $store = match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/i', $agent) => $stores['ios'],
            (bool) preg_match('/Android/i', $agent) => $stores['android'],
            default => null,
        };

        $redirect = $store ? redirect()->away($store) : redirect(route('landing').'#unduh');

        // Jangan di-cache: tautan toko bisa diisi kapan saja setelah aplikasi tayang.
        return $redirect->header('Cache-Control', 'no-store');
    }

    /**
     * Hanya URL https yang valid yang dianggap tayang; selain itu tombol tampil "Segera hadir".
     *
     * @return array{android: ?string, ios: ?string}
     */
    private function stores(): array
    {
        $valid = fn (mixed $url): ?string => is_string($url) && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;

        return [
            'android' => $valid(config('loyalty.stores.android')),
            'ios' => $valid(config('loyalty.stores.ios')),
        ];
    }

    /**
     * Brand dan jumlah outlet aktif dari database, di-cache sebentar agar landing page ringan.
     * Bila gagal (mis. database belum siap) halaman tetap tampil tanpa bagian ini.
     *
     * @return array{brands: list<array{name: string, category: string, color: ?string, logo_path: ?string}>, outlets: int}
     */
    private function directory(): array
    {
        try {
            return Cache::remember('landing.directory', 300, fn () => [
                'brands' => Brand::query()
                    ->orderBy('name')
                    ->get(['name', 'category', 'color', 'logo_path'])
                    ->map(fn (Brand $brand) => $brand->only(['name', 'category', 'color', 'logo_path']))
                    ->all(),
                'outlets' => Outlet::where('is_active', true)->count(),
            ]);
        } catch (Throwable) {
            return ['brands' => [], 'outlets' => 0];
        }
    }
}
