<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PointTransactionResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AccountService;
use App\Services\LoyaltyService;
use App\Services\MemberQr;
use App\Services\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show(Request $request, LoyaltyService $loyalty, VoucherService $vouchers): JsonResponse
    {
        // Saat aplikasi dibuka: terbitkan voucher ulang tahun bila bulan ini bulan lahirnya.
        $vouchers->issueBirthdayVouchers($request->user());

        return response()->json([
            'user' => new UserResource($request->user()),
            'loyalty' => $loyalty->summary($request->user()),
        ]);
    }

    public function update(Request $request): UserResource
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150', Rule::unique('users')->ignore($user->id)],
            // Tanggal lahir dipakai untuk birthday reward, hanya bisa diisi sekali agar tidak disalahgunakan.
            'birth_date' => [$user->birth_date ? 'prohibited' : 'sometimes', 'date', 'before:today', 'after:1900-01-01'],
            'last_latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'last_longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ], [
            'birth_date.prohibited' => 'Tanggal lahir sudah tersimpan. Hubungi CS untuk mengubahnya.',
        ]);

        $user->fill($data)->save();

        return new UserResource($user);
    }

    /**
     * Foto profil member (multipart, field "file"). Nama file acak sehingga URL tidak bisa ditebak.
     */
    public function uploadAvatar(Request $request): UserResource
    {
        $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=100,min_height=100'],
        ], [
            'file.max' => 'Ukuran foto maksimal 5 MB.',
            'file.dimensions' => 'Foto terlalu kecil, minimal 100×100 piksel.',
        ]);

        // Foto lama dihapus otomatis oleh model (DeletesReplacedMedia).
        $user = $request->user();
        $user->forceFill(['avatar_path' => $request->file('file')->store('avatars', 'media')])->save();

        return new UserResource($user);
    }

    public function deleteAvatar(Request $request): UserResource
    {
        $user = $request->user();
        $user->forceFill(['avatar_path' => null])->save();

        return new UserResource($user);
    }

    /**
     * Member menghapus akunnya sendiri (wajib ada di aplikasi menurut aturan App Store & Play Store).
     * Akun kasir/admin dikelola dari panel admin.
     */
    public function destroy(Request $request, AccountService $accounts): JsonResponse
    {
        $request->validate(['confirm' => ['accepted']]);

        $user = $request->user();
        abort_if($user->role !== User::ROLE_MEMBER, 403, 'Akun kasir/admin hanya bisa dihapus oleh admin.');

        $accounts->delete($user);

        return response()->json(['message' => 'Akun sudah dihapus.']);
    }

    public function qr(Request $request, MemberQr $qr): JsonResponse
    {
        return response()->json($qr->issue($request->user()));
    }

    public function points(Request $request, LoyaltyService $loyalty): AnonymousResourceCollection
    {
        $history = $request->user()->pointTransactions()
            ->with('outlet.brand')
            ->latest('id')
            ->paginate(20);

        return PointTransactionResource::collection($history)
            ->additional(['loyalty' => $loyalty->summary($request->user())]);
    }

    public function tiers(): JsonResponse
    {
        return response()->json([
            'point_value' => config('loyalty.point_value'),
            'tiers' => collect(config('loyalty.tiers'))->map(fn ($tier, $key) => ['key' => $key, ...$tier])->values(),
        ]);
    }
}
