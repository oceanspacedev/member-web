<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Log;

/**
 * Driver development: pesan hanya ditulis ke log, tidak benar-benar dikirim.
 */
class LogWhatsAppSender implements WhatsAppSender
{
    public function send(string $phone, string $message, string $purpose = 'notification'): void
    {
        Log::info("[WhatsApp:log] ({$purpose}) to {$phone}: {$message}");
    }
}
