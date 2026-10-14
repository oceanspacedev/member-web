<?php

namespace App\Services\Receipts;

use RuntimeException;

/** Pembaca otomatis tidak tersedia (belum dikonfigurasi, gagal, atau menolak); struk diperiksa admin. */
class ReceiptReaderUnavailable extends RuntimeException {}
