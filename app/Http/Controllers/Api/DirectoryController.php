<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Http\Resources\OutletResource;
use App\Http\Resources\PromoResource;
use App\Models\Brand;
use App\Models\Outlet;
use App\Models\Promo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DirectoryController extends Controller
{
    public function brands(): AnonymousResourceCollection
    {
        return BrandResource::collection(Brand::withCount('outlets')->orderBy('name')->get());
    }

    /**
     * Daftar outlet aktif, diurutkan dari yang terdekat bila lat/lng dikirim.
     */
    public function outlets(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'category' => ['nullable', Rule::in(array_keys(config('loyalty.brand_categories')))],
            'brand_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $lat = $request->filled('lat') ? (float) $request->input('lat') : null;
        $lng = $request->filled('lng') ? (float) $request->input('lng') : null;

        $outlets = Outlet::with('brand')
            ->where('is_active', true)
            ->when($request->input('category'), fn (Builder $q, $cat) => $q->whereHas('brand', fn (Builder $b) => $b->where('category', $cat)))
            ->when($request->input('brand_id'), fn (Builder $q, $id) => $q->where('brand_id', $id))
            ->when($request->input('q'), fn (Builder $q, $term) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('city', 'like', "%{$term}%")
                ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', "%{$term}%"))))
            ->get()
            ->each(fn (Outlet $o) => $o->setAttribute('distance_km', $o->distanceTo($lat, $lng)))
            ->sortBy([
                fn (Outlet $a, Outlet $b) => ($a->distance_km === null) <=> ($b->distance_km === null),
                fn (Outlet $a, Outlet $b) => [$a->distance_km, $a->name] <=> [$b->distance_km, $b->name],
            ])
            ->values();

        return OutletResource::collection($outlets);
    }

    public function outlet(Outlet $outlet): OutletResource
    {
        abort_unless($outlet->is_active, 404);

        return new OutletResource($outlet->load('brand'));
    }

    public function promos(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'category' => ['nullable', Rule::in(array_keys(config('loyalty.brand_categories')))],
            'brand_id' => ['nullable', 'integer'],
        ]);

        // Rute publik; bila ada token member, sertakan status klaimnya per promo.
        $user = $request->user('sanctum');

        $promos = Promo::with('brand')
            ->running()
            ->inCatalog()
            ->withCount(['orders as open_orders_count' => fn (Builder $o) => $o->open()])
            ->when($user, fn (Builder $q) => $q
                ->withCount(['vouchers as my_vouchers_count' => fn (Builder $v) => $v->where('user_id', $user->id)])
                ->withMax(['orders as my_open_order_id' => fn (Builder $o) => $o->open()->where('user_id', $user->id)], 'id'))
            ->when($request->input('category'), fn (Builder $q, $cat) => $q->where(fn (Builder $w) => $w
                ->whereNull('brand_id')
                ->orWhereHas('brand', fn (Builder $b) => $b->where('category', $cat))))
            ->when($request->input('brand_id'), fn (Builder $q, $id) => $q->where(fn (Builder $w) => $w
                ->whereNull('brand_id')
                ->orWhere('brand_id', $id)))
            ->orderBy('ends_at')
            ->get();

        return PromoResource::collection($promos);
    }
}
