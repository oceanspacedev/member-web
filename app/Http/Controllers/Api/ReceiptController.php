<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReceiptClaimResource;
use App\Jobs\VerifyReceiptClaim;
use App\Models\ReceiptClaim;
use App\Services\ReceiptService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Klaim poin dari foto struk (outlet dengan POS pihak ketiga).
 */
class ReceiptController extends Controller
{
    /** Struk yang pernah dikirim member, terbaru dulu. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ReceiptClaimResource::collection(
            ReceiptClaim::with(['outlet', 'transaction'])->where('user_id', $request->user()->id)->latest('id')->paginate(20),
        );
    }

    /**
     * Status satu struk
     *
     * Dipanggil berkala oleh aplikasi selama `checking` = true (biasanya beberapa detik).
     */
    public function show(Request $request, ReceiptClaim $receiptClaim): ReceiptClaimResource
    {
        abort_unless($receiptClaim->user_id === $request->user()->id, 404);

        return new ReceiptClaimResource($receiptClaim->load(['outlet', 'transaction']));
    }

    /**
     * Kirim foto struk
     *
     * Multipart: `photo` (foto struk). Outlet, nomor struk, tanggal, dan total dibaca sistem dari foto,
     * lalu poin masuk otomatis bila struk lolos pemeriksaan. `outlet_id` opsional (aplikasi versi lama).
     */
    public function store(Request $request, ReceiptService $receipts): ReceiptClaimResource
    {
        $data = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,heic', 'max:8192'],
            'outlet_id' => ['nullable', 'integer'],
        ], [
            'photo.max' => 'Ukuran foto maksimal 8 MB.',
        ]);

        $claim = $receipts->submit($request->user(), $request->file('photo'), isset($data['outlet_id']) ? (int) $data['outlet_id'] : null);
        VerifyReceiptClaim::for($claim);

        return new ReceiptClaimResource($claim->load('outlet'));
    }
}
