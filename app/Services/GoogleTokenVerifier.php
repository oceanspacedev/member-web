<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class GoogleTokenVerifier
{
    /**
     * Verifikasi Google ID token dari aplikasi mobile.
     *
     * @return array{sub: string, email: ?string, name: ?string}
     */
    public function verify(string $idToken): array
    {
        $allowed = config('loyalty.google_client_ids');

        if ($allowed === []) {
            throw ValidationException::withMessages(['id_token' => 'Login Google belum dikonfigurasi (GOOGLE_CLIENT_IDS).']);
        }

        $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);
        $claims = $response->json() ?? [];

        $valid = $response->ok()
            && in_array($claims['aud'] ?? null, $allowed, true)
            && in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            && (int) ($claims['exp'] ?? 0) > time()
            && filled($claims['sub'] ?? null);

        if (! $valid) {
            throw ValidationException::withMessages(['id_token' => 'Token Google tidak valid.']);
        }

        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'sub' => $claims['sub'],
            'email' => $emailVerified ? ($claims['email'] ?? null) : null,
            'name' => $claims['name'] ?? null,
        ];
    }
}
