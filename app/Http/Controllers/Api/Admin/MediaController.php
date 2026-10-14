<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Http\Resources\OutletResource;
use App\Http\Resources\PromoResource;
use App\Models\Brand;
use App\Models\Outlet;
use App\Models\Promo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Upload gambar oleh admin: logo brand, foto outlet, gambar promo.
 * Body multipart dengan field "file" (jpg/png/webp, maks 3 MB).
 */
class MediaController extends Controller
{
    public function brandLogo(Request $request, Brand $brand): BrandResource
    {
        $this->replace($request, $brand, 'logo_path', 'brands');

        return new BrandResource($brand);
    }

    public function outletPhoto(Request $request, Outlet $outlet): OutletResource
    {
        $this->replace($request, $outlet, 'photo_path', 'outlets');

        return new OutletResource($outlet->load('brand'));
    }

    public function promoImage(Request $request, Promo $promo): PromoResource
    {
        $this->replace($request, $promo, 'image_path', 'promos');

        return new PromoResource($promo->load('brand'));
    }

    private function replace(Request $request, Model $model, string $column, string $folder): void
    {
        $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072', 'dimensions:min_width=300,min_height=200'],
        ]);

        // File lama dihapus otomatis oleh model (DeletesReplacedMedia).
        $model->{$column} = $request->file('file')->store($folder, 'media');
        $model->save();
    }
}
