<?php

namespace App\Services\WhatsApp;

/**
 * Lingkungan lokal: nomor akun demo seeder (bisa saja milik orang sungguhan)
 * tidak pernah dikirimi WhatsApp — pesannya hanya masuk log.
 */
class DemoNumberSender implements WhatsAppSender
{
    /**
     * @param  list<string>  $demoNumbers
     */
    public function __construct(
        private readonly WhatsAppSender $inner,
        private readonly array $demoNumbers,
        private readonly WhatsAppSender $log = new LogWhatsAppSender,
    ) {}

    public function send(string $phone, string $message, string $purpose = 'notification'): void
    {
        $sender = in_array($phone, $this->demoNumbers, true) ? $this->log : $this->inner;

        $sender->send($phone, $message, $purpose);
    }
}
