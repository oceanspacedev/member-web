<?php

namespace App\Services\WhatsApp;

interface WhatsAppSender
{
    /**
     * Kirim pesan teks ke nomor berformat 62xxxxxxxxxx.
     *
     * @param  'otp'|'transactional'|'notification'  $purpose
     *
     * @throws WhatsAppDeliveryException bila gateway menolak / tidak bisa dihubungi
     */
    public function send(string $phone, string $message, string $purpose = 'notification'): void;
}
