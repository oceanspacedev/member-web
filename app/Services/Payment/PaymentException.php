<?php

namespace App\Services\Payment;

use RuntimeException;

/** Gateway belum dikonfigurasi, tidak bisa dihubungi, atau menolak permintaan. */
class PaymentException extends RuntimeException {}
