<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\GoogleTokenVerifier;
use App\Services\OtpService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function requestOtp(Request $request, OtpService $otp): JsonResponse
    {
        $phone = $this->phone($request);
        $ttl = $otp->send($phone);

        return response()->json([
            'message' => 'Kode OTP dikirim via WhatsApp.',
            'phone' => $phone,
            'expires_in' => $ttl,
            'resend_in' => config('loyalty.otp.resend_cooldown'),
        ]);
    }

    public function verifyOtp(Request $request, OtpService $otp): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'digits:'.config('loyalty.otp.length')],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $phone = $this->phone($request);
        $otp->verify($phone, $request->string('code'));

        $user = User::firstOrNew(['phone' => $phone]);
        $isNew = ! $user->exists;
        $user->phone_verified_at ??= now();
        $user->save();

        return $this->tokenResponse($user, $request, $isNew);
    }

    public function google(Request $request, GoogleTokenVerifier $google): JsonResponse
    {
        $request->validate([
            'id_token' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $claims = $google->verify($request->string('id_token'));

        $user = User::where('google_id', $claims['sub'])->first()
            ?? ($claims['email'] ? User::where('email', $claims['email'])->first() : null)
            ?? new User;

        $isNew = ! $user->exists;
        $user->google_id = $claims['sub'];
        $user->email ??= $claims['email'];
        $user->name ??= $claims['name'];
        if ($claims['email']) {
            $user->email_verified_at ??= now();
        }
        $user->save();

        return $this->tokenResponse($user, $request, $isNew);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    private function phone(Request $request): string
    {
        $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $phone = Phone::normalize($request->input('phone'));

        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Nomor HP tidak valid.']);
        }

        return $phone;
    }

    private function tokenResponse(User $user, Request $request, bool $isNew): JsonResponse
    {
        $abilities = $user->isStaff() ? ['member', 'pos'] : ['member'];
        $device = $request->input('device_name') ?: 'mobile';
        $token = $user->createToken($device, $abilities)->plainTextToken;

        // Jejak login untuk panel admin (Member → Terakhir aktif / Perangkat).
        $user->forceFill(['last_login_at' => now(), 'last_seen_at' => now(), 'last_device' => Str::limit($device, 40, '')])->save();

        return response()->json([
            'token' => $token,
            'is_new' => $isNew,
            'user' => new UserResource($user->fresh()),
        ]);
    }
}
