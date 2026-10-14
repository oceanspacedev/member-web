<?php

namespace App\Http\Middleware;

use App\Models\PartnerKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi API partner POS: header "Authorization: Bearer osk_...".
 * Key yang valid disimpan di request agar controller tahu outlet mana yang memanggil.
 */
class AuthenticatePartnerKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();
        $key = str_starts_with($plain, PartnerKey::PREFIX) ? PartnerKey::findActive($plain) : null;

        if (! $key || ! $key->outlet?->is_active) {
            return response()->json(['message' => 'API key tidak valid atau sudah dicabut.'], 401);
        }

        // Catat pemakaian paling sering sekali per menit agar tiap request tidak menulis ke database.
        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('partner_key', $key);

        return $next($request);
    }
}
