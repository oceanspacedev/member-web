<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Catat "terakhir aktif" member setiap kali aplikasi memanggil API — paling sering sekali per
 * 5 menit agar tidak menulis ke database di setiap request.
 */
class TrackLastSeen
{
    private const EVERY_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if ($user instanceof User && ! $user->trashed() && ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subMinutes(self::EVERY_MINUTES)))) {
            $token = $user->currentAccessToken();

            // Lewat query langsung: tidak mengubah updated_at dan tidak memicu event model.
            User::whereKey($user->id)->toBase()->update([
                'last_seen_at' => now(),
                'last_device' => $token instanceof PersonalAccessToken ? Str::limit($token->name, 40, '') : $user->last_device,
            ]);
        }

        return $response;
    }
}
