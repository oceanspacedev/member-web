<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\PartnerKey;
use App\Models\PointTransaction;
use App\Models\Promo;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherOrder;
use App\Services\Payment\PaymentException;
use App\Services\Payment\PaymentGateway;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class VoucherService
{
    /** Jeda minimum antar-pengecekan status ke gateway untuk satu tagihan (aplikasi mengecek berkala). */
    private const STATUS_CHECK_INTERVAL = 3;

    /** Pemakaian voucher lewat API partner bisa dibatalkan POS dalam jangka waktu ini. */
    public const PARTNER_CANCEL_HOURS = 24;

    public function __construct(
        private LoyaltyService $loyalty,
        private PaymentGateway $gateway,
    ) {}

    /**
     * Member mengambil voucher dari katalog: klaim gratis atau tukar poin.
     */
    public function acquire(User $member, Promo $promo): Voucher
    {
        return DB::transaction(function () use ($member, $promo) {
            $promo = $this->lockAvailable($member, $promo);

            $purchase = $promo->type === Promo::TYPE_SALE;
            if ($purchase && $promo->price_points === null) {
                $this->fail('Voucher ini dibeli lewat pembayaran, bukan poin.');
            }
            if ($purchase) {
                $this->loyalty->spendForVoucher($member, $promo->price_points, $promo->title);
            }

            return $this->issue($member, $promo, $this->snapshot($promo) + [
                'source' => $purchase ? Voucher::SOURCE_PURCHASE : Voucher::SOURCE_CLAIM,
                'points_spent' => $purchase ? $promo->price_points : 0,
            ]);
        });
    }

    /**
     * Beli voucher dengan uang: buat tagihan + halaman bayar di gateway. Voucher terbit setelah lunas.
     * Tagihan yang masih terbuka untuk promo yang sama dipakai ulang (tombol beli ditekan dua kali).
     */
    public function startPurchase(User $member, Promo $promo): VoucherOrder
    {
        $existing = VoucherOrder::open()->where('user_id', $member->id)->where('promo_id', $promo->id)->whereNotNull('payment_url')->latest('id')->first();
        if ($existing) {
            return $existing;
        }

        $order = DB::transaction(function () use ($member, $promo) {
            $promo = $this->lockAvailable($member, $promo);

            if ($promo->type !== Promo::TYPE_SALE || ! $promo->price_rupiah) {
                $this->fail('Voucher ini tidak dijual dengan pembayaran.');
            }

            return VoucherOrder::create([
                'reference' => VoucherOrder::newReference(),
                'user_id' => $member->id,
                'promo_id' => $promo->id,
                'title' => $promo->title,
                // Masa berlaku dihitung saat lunas, bukan saat tagihan dibuat.
                'snapshot' => Arr::except($this->snapshot($promo), 'expires_at') + ['valid_days' => $promo->valid_days, 'ends_at' => $promo->ends_at->toDateString()],
                'amount' => $promo->price_rupiah,
                'gateway' => $this->gateway->name(),
                'expires_at' => now()->addMinutes(config('loyalty.payment.order_ttl')),
            ]);
        });

        // Panggilan jaringan di luar transaksi agar kunci database tidak tertahan.
        try {
            $checkout = $this->gateway->createCheckout($order, $member);
        } catch (PaymentException $e) {
            $order->forceFill(['status' => VoucherOrder::STATUS_CANCELED])->save();
            $this->fail($e->getMessage());
        }

        $order->forceFill(['gateway_session_id' => $checkout['session_id'], 'payment_url' => $checkout['url']])->save();

        return $order;
    }

    /**
     * Perbarui status tagihan dari gateway; terbitkan voucher bila sudah lunas.
     * Dipanggil oleh webhook dan saat aplikasi mengecek status.
     */
    public function refreshOrder(VoucherOrder $order, bool $force = false): VoucherOrder
    {
        if (! $order->isPending()) {
            return $order;
        }

        if (! $order->gateway_session_id) {
            if ($order->expires_at->isPast()) {
                $order->forceFill(['status' => VoucherOrder::STATUS_EXPIRED])->save();
            }

            return $order;
        }

        if (! $force && $order->checked_at?->gt(now()->subSeconds(self::STATUS_CHECK_INTERVAL))) {
            return $order;
        }

        try {
            $status = $this->gateway->status($order->gateway_session_id);
        } catch (PaymentException $e) {
            Log::warning("[Payment] status {$order->reference} gagal dicek: {$e->getMessage()}");

            return $order;
        }

        $order->forceFill(['checked_at' => now()])->save();

        if ($status === PaymentGateway::STATUS_PAID) {
            return $this->fulfill($order);
        }

        $closed = [PaymentGateway::STATUS_EXPIRED => VoucherOrder::STATUS_EXPIRED, PaymentGateway::STATUS_CANCELED => VoucherOrder::STATUS_CANCELED];
        if (isset($closed[$status])) {
            $order->forceFill(['status' => $closed[$status]])->save();
        }

        return $order;
    }

    /**
     * Tandai lunas dan terbitkan voucher tepat sekali, walau webhook dan pengecekan aplikasi datang bersamaan.
     * Member yang sudah membayar selalu mendapat voucher, meski promo sudah berakhir atau kuota penuh.
     */
    public function fulfill(VoucherOrder $order): VoucherOrder
    {
        return DB::transaction(function () use ($order) {
            $order = VoucherOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status === VoucherOrder::STATUS_PAID) {
                return $order;
            }

            $snapshot = $order->snapshot;
            $expires = $snapshot['valid_days']
                ? now($this->tz())->addDays($snapshot['valid_days'])->endOfDay()
                : Carbon::parse($snapshot['ends_at'], $this->tz())->endOfDay();

            $voucher = $this->issue($order->user, $order->promo, [
                'source' => Voucher::SOURCE_PAID,
                'title' => $snapshot['title'],
                'brand_id' => $snapshot['brand_id'],
                'value' => $snapshot['value'],
                'min_spend' => $snapshot['min_spend'],
                'paid_amount' => $order->amount,
                // Jaga-jaga bila tanggal berakhir promo sudah lewat saat pembayaran dikonfirmasi.
                'expires_at' => $expires->isPast() ? now($this->tz())->addDays(7)->endOfDay() : $expires,
            ]);

            $order->forceFill(['status' => VoucherOrder::STATUS_PAID, 'paid_at' => now(), 'voucher_id' => $voucher->id])->save();

            return $order;
        });
    }

    /**
     * Voucher ulang tahun: terbit di bulan lahir member, sekali per tahun, berlaku sampai akhir bulan.
     * Dipanggil saat member membuka aplikasi dan oleh jadwal harian.
     */
    public function issueBirthdayVouchers(User $member): void
    {
        $now = now($this->tz());

        if (! $member->birth_date || $member->birth_date->month !== $now->month) {
            return;
        }

        $promos = Promo::running()->where('type', Promo::TYPE_BIRTHDAY)->get();

        foreach ($promos as $promo) {
            if (! $this->loyalty->meetsTier($member->tier, $promo->min_tier)) {
                continue;
            }

            $period = (string) $now->year;
            if ($promo->vouchers()->where('user_id', $member->id)->where('period', $period)->exists()) {
                continue;
            }

            try {
                // Kunci kiri menang pada operator +: masa berlaku akhir bulan menimpa bawaan promo.
                DB::transaction(fn () => $this->issue($member, $promo, [
                    'source' => Voucher::SOURCE_BIRTHDAY,
                    'period' => $period,
                    'expires_at' => $now->copy()->endOfMonth(),
                ] + $this->snapshot($promo)));
            } catch (UniqueConstraintViolationException) {
                // Sudah diterbitkan oleh request lain yang berjalan bersamaan.
            }
        }
    }

    /**
     * Cek voucher di kasir sebelum dipakai. Melempar ValidationException berisi alasan penolakan.
     */
    public function ensureUsable(Voucher $voucher, Outlet $outlet, ?int $amount = null): void
    {
        if ($voucher->used_at) {
            $this->fail('Voucher sudah dipakai pada '.$voucher->used_at->timezone($this->tz())->translatedFormat('d M Y H:i').($voucher->outlet ? " di {$voucher->outlet->name}." : '.'));
        }
        if ($voucher->expires_at->isPast()) {
            $this->fail('Voucher sudah kedaluwarsa.');
        }
        if ($voucher->brand_id !== null && $voucher->brand_id !== $outlet->brand_id) {
            $this->fail('Voucher ini hanya berlaku di '.($voucher->brand?->name ?? 'brand lain').'.');
        }
        if ($amount !== null && $amount < $voucher->min_spend) {
            $this->fail('Minimal belanja untuk voucher ini Rp'.number_format($voucher->min_spend, 0, ',', '.').'.');
        }
    }

    /**
     * Pakai voucher di kasir: potong tagihan, lalu poin pemilik voucher dihitung dari sisa yang dibayar.
     * Idempoten per (outlet, nomor struk): request ulang dengan voucher & struk yang sama mengembalikan hasil yang sama.
     *
     * @return array{voucher: Voucher, earn: ?PointTransaction}
     */
    public function redeem(Voucher $voucher, Outlet $outlet, int $amount, string $reference, User $cashier): array
    {
        $this->markUsed($voucher, $outlet, $amount, $reference, ['cashier_id' => $cashier->id]);

        $voucher->refresh();
        $paid = (int) $voucher->bill_amount - (int) $voucher->discount;
        $earn = $paid > 0
            ? $this->loyalty->earn($voucher->user, $outlet, $paid, $reference, $voucher->cashier ?? $cashier)
            : null;

        return ['voucher' => $voucher, 'earn' => $earn];
    }

    /**
     * Pakai voucher dari POS pihak ketiga (API partner). Aturannya sama dengan kasir kita dan idempoten
     * per (outlet, nomor struk). Poin belum dicatat: belanjanya terjadi di POS yang bukan milik kita.
     */
    public function redeemForPartner(Voucher $voucher, PartnerKey $key, int $amount, string $reference): Voucher
    {
        $this->markUsed($voucher, $key->outlet, $amount, $reference, ['partner_key_id' => $key->id]);

        return $voucher->refresh();
    }

    /**
     * Batalkan pemakaian voucher dari POS (transaksi di POS dibatalkan/di-void), agar voucher bisa dipakai lagi.
     * Hanya untuk struk yang sama, di outlet yang sama, dipakai lewat API partner, dalam batas waktu.
     */
    public function cancelForPartner(Voucher $voucher, PartnerKey $key, string $reference): Voucher
    {
        return DB::transaction(function () use ($voucher, $key, $reference) {
            $locked = Voucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if (! $locked->used_at || $locked->outlet_id !== $key->outlet_id || $locked->reference !== $reference) {
                $this->fail('Voucher ini tidak dipakai pada struk tersebut di outlet ini.');
            }
            if ($locked->partner_key_id === null) {
                $this->fail('Voucher ini dipakai lewat kasir Ocean Space dan tidak bisa dibatalkan dari POS.');
            }
            if ($locked->used_at->lt(now()->subHours(self::PARTNER_CANCEL_HOURS))) {
                $this->fail('Batas waktu pembatalan ('.self::PARTNER_CANCEL_HOURS.' jam) sudah lewat. Hubungi admin Ocean Space.');
            }

            $locked->forceFill([
                'used_at' => null,
                'outlet_id' => null,
                'cashier_id' => null,
                'partner_key_id' => null,
                'reference' => null,
                'bill_amount' => null,
                'discount' => null,
            ])->save();

            return $locked;
        });
    }

    /**
     * Cari voucher dari kode yang diketik/discan kasir: abaikan huruf kecil, spasi, dan tanda hubung.
     */
    public function findByCode(string $code): ?Voucher
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        return $normalized === '' ? null : Voucher::with(['brand', 'outlet'])->where('code', $normalized)->first();
    }

    /**
     * Tandai voucher terpakai untuk satu struk, tepat sekali walau request datang bersamaan.
     *
     * @param  array<string, int>  $by  cashier_id atau partner_key_id
     */
    private function markUsed(Voucher $voucher, Outlet $outlet, int $amount, string $reference, array $by): void
    {
        $existing = Voucher::where('outlet_id', $outlet->id)->where('reference', $reference)->first();

        if ($existing && ! $existing->is($voucher)) {
            $this->fail('Struk ini sudah memakai voucher lain.');
        }

        // Request ulang untuk struk yang sama (mis. koneksi POS putus): kembalikan hasil sebelumnya.
        if ($existing) {
            return;
        }

        try {
            DB::transaction(function () use ($voucher, $outlet, $amount, $reference, $by) {
                $locked = Voucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();
                $this->ensureUsable($locked, $outlet, $amount);

                $locked->forceFill($by + [
                    'used_at' => now(),
                    'outlet_id' => $outlet->id,
                    'reference' => $reference,
                    'bill_amount' => $amount,
                    'discount' => min($locked->value, $amount),
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            $this->fail('Struk ini sudah memakai voucher lain.');
        }
    }

    /**
     * Kunci promo lalu cek syarat ambil voucher. Tagihan yang belum dibayar ikut dihitung
     * untuk kuota dan batas per member, agar voucher tidak terjual melebihi kuota.
     */
    private function lockAvailable(User $member, Promo $promo): Promo
    {
        $promo = Promo::whereKey($promo->id)->lockForUpdate()->firstOrFail();

        if (! $promo->isAcquirable() || ! Promo::whereKey($promo->id)->running()->exists()) {
            $this->fail('Promo ini tidak bisa diklaim.');
        }
        if (! $this->loyalty->meetsTier($member->tier, $promo->min_tier)) {
            $this->fail('Voucher ini khusus member '.config("loyalty.tiers.{$promo->min_tier}.label").' ke atas.');
        }
        if ($promo->quotaLeft() === 0) {
            $this->fail('Kuota voucher sudah habis.');
        }

        $taken = $promo->vouchers()->where('user_id', $member->id)->count()
            + $promo->orders()->open()->where('user_id', $member->id)->count();
        if ($taken >= $promo->per_member_limit) {
            $this->fail('Kamu sudah mengambil voucher ini.');
        }

        return $promo;
    }

    /** @return array{title: string, brand_id: ?int, value: int, min_spend: int, expires_at: CarbonInterface} */
    private function snapshot(Promo $promo): array
    {
        return [
            'title' => $promo->title,
            'brand_id' => $promo->brand_id,
            'value' => (int) $promo->voucher_value,
            'min_spend' => $promo->min_spend,
            'expires_at' => $promo->valid_days
                ? now($this->tz())->addDays($promo->valid_days)->endOfDay()
                : $promo->ends_at->copy()->shiftTimezone($this->tz())->endOfDay(),
        ];
    }

    private function issue(User $member, ?Promo $promo, array $attributes): Voucher
    {
        $promo?->increment('issued_count');

        return Voucher::create([
            'promo_id' => $promo?->id,
            'user_id' => $member->id,
            'code' => Voucher::generateCode(),
            ...$attributes,
            'expires_at' => $attributes['expires_at']->copy()->utc(),
        ]);
    }

    private function tz(): string
    {
        return config('loyalty.timezone');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['voucher' => $message]);
    }
}
