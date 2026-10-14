<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyService
{
    /**
     * Tambah poin dari transaksi belanja. Idempoten per (outlet, nomor struk).
     */
    public function earn(User $member, Outlet $outlet, int $amount, ?string $reference, ?User $cashier = null): PointTransaction
    {
        if ($existing = $this->findByReference($outlet, PointTransaction::TYPE_EARN, $reference)) {
            return $existing;
        }

        return $this->once($outlet, PointTransaction::TYPE_EARN, $reference, fn () => DB::transaction(function () use ($member, $outlet, $amount, $reference, $cashier) {
            $member = User::whereKey($member->id)->lockForUpdate()->firstOrFail();
            $tier = $this->tier($member->tier);
            $points = intdiv($amount * $tier['cashback_percent'], 100 * config('loyalty.point_value'));

            $member->points_balance += $points;
            $member->tier = $this->tierFor($this->annualSpend($member) + $amount);
            $member->save();

            return PointTransaction::create([
                'user_id' => $member->id,
                'outlet_id' => $outlet->id,
                'cashier_id' => $cashier?->id,
                'type' => PointTransaction::TYPE_EARN,
                'amount' => $amount,
                'points' => $points,
                'balance_after' => $member->points_balance,
                'reference' => $reference,
                'description' => "Belanja di {$outlet->name} (cashback {$tier['cashback_percent']}%)",
            ]);
        }));
    }

    /**
     * Tukar poin sebagai potongan tagihan di kasir. Idempoten per (outlet, nomor struk).
     */
    public function redeem(User $member, Outlet $outlet, int $points, ?string $reference, ?User $cashier = null): PointTransaction
    {
        if ($existing = $this->findByReference($outlet, PointTransaction::TYPE_REDEEM, $reference)) {
            return $existing;
        }

        return $this->once($outlet, PointTransaction::TYPE_REDEEM, $reference, fn () => DB::transaction(function () use ($member, $outlet, $points, $reference, $cashier) {
            $member = User::whereKey($member->id)->lockForUpdate()->firstOrFail();

            if ($member->points_balance < $points) {
                throw ValidationException::withMessages([
                    'points' => "Poin tidak cukup. Saldo member {$member->points_balance} poin.",
                ]);
            }

            $member->points_balance -= $points;
            $member->save();

            return PointTransaction::create([
                'user_id' => $member->id,
                'outlet_id' => $outlet->id,
                'cashier_id' => $cashier?->id,
                'type' => PointTransaction::TYPE_REDEEM,
                'amount' => $points * config('loyalty.point_value'),
                'points' => -$points,
                'balance_after' => $member->points_balance,
                'reference' => $reference,
                'description' => "Tukar poin di {$outlet->name}",
            ]);
        }));
    }

    /**
     * Penyesuaian poin manual oleh admin (kompensasi, koreksi). Tidak memengaruhi tier.
     *
     * @param  int  $points  positif = tambah, negatif = kurangi
     */
    public function adjust(User $member, int $points, string $reason, User $admin): PointTransaction
    {
        if ($points === 0) {
            throw ValidationException::withMessages(['points' => 'Jumlah poin tidak boleh 0.']);
        }

        return DB::transaction(function () use ($member, $points, $reason, $admin) {
            $member = User::whereKey($member->id)->lockForUpdate()->firstOrFail();

            if ($member->points_balance + $points < 0) {
                throw ValidationException::withMessages([
                    'points' => "Saldo member hanya {$member->points_balance} poin.",
                ]);
            }

            $member->points_balance += $points;
            $member->save();

            return PointTransaction::create([
                'user_id' => $member->id,
                'cashier_id' => $admin->id,
                'type' => PointTransaction::TYPE_ADJUST,
                'amount' => 0,
                'points' => $points,
                'balance_after' => $member->points_balance,
                'description' => 'Penyesuaian admin: '.$reason,
            ]);
        });
    }

    /**
     * Potong poin untuk voucher dari katalog. Panggil di dalam transaksi pemanggil.
     */
    public function spendForVoucher(User $member, int $points, string $title): PointTransaction
    {
        $member = User::whereKey($member->id)->lockForUpdate()->firstOrFail();

        if ($member->points_balance < $points) {
            throw ValidationException::withMessages([
                'points' => 'Poin kamu belum cukup. Butuh '.number_format($points, 0, ',', '.').' poin, saldo '.number_format($member->points_balance, 0, ',', '.').'.',
            ]);
        }

        $member->points_balance -= $points;
        $member->save();

        return PointTransaction::create([
            'user_id' => $member->id,
            'type' => PointTransaction::TYPE_VOUCHER,
            'amount' => $points * config('loyalty.point_value'),
            'points' => -$points,
            'balance_after' => $member->points_balance,
            'description' => "Tukar poin: {$title}",
        ]);
    }

    /**
     * Apakah tier member memenuhi syarat minimal (null = semua tier).
     */
    public function meetsTier(string $tier, ?string $minTier): bool
    {
        if ($minTier === null) {
            return true;
        }

        $order = array_keys(config('loyalty.tiers'));

        return array_search($tier, $order, true) >= array_search($minTier, $order, true);
    }

    /**
     * Total belanja 12 bulan terakhir (dasar perhitungan tier).
     */
    public function annualSpend(User $member): int
    {
        return (int) $member->pointTransactions()
            ->where('type', PointTransaction::TYPE_EARN)
            ->where('created_at', '>=', now()->subYear())
            ->sum('amount');
    }

    public function tierFor(int $annualSpend): string
    {
        $current = 'silver';

        foreach (config('loyalty.tiers') as $key => $tier) {
            if ($annualSpend >= $tier['min_annual_spend']) {
                $current = $key;
            }
        }

        return $current;
    }

    /**
     * Ringkasan tier untuk ditampilkan di aplikasi.
     *
     * @return array<string, mixed>
     */
    public function summary(User $member): array
    {
        $spend = $this->annualSpend($member);
        $tiers = config('loyalty.tiers');
        $keys = array_keys($tiers);
        $next = $keys[array_search($member->tier, $keys, true) + 1] ?? null;

        return [
            'tier' => $member->tier,
            'tier_label' => $tiers[$member->tier]['label'],
            'cashback_percent' => $tiers[$member->tier]['cashback_percent'],
            'points_balance' => $member->points_balance,
            'points_value' => $member->points_balance * config('loyalty.point_value'),
            'annual_spend' => $spend,
            'next_tier' => $next,
            'next_tier_label' => $next ? $tiers[$next]['label'] : null,
            'next_tier_spend' => $next ? $tiers[$next]['min_annual_spend'] : null,
            'spend_to_next_tier' => $next ? max(0, $tiers[$next]['min_annual_spend'] - $spend) : 0,
            'active_vouchers' => $member->vouchers()->active()->count(),
        ];
    }

    /**
     * @return array{label: string, min_annual_spend: int, cashback_percent: int}
     */
    private function tier(string $key): array
    {
        return config("loyalty.tiers.{$key}") ?? config('loyalty.tiers.silver');
    }

    /**
     * Jalankan transaksi; bila dua request dengan struk sama lolos bersamaan, kembalikan yang sudah tercatat.
     */
    private function once(Outlet $outlet, string $type, ?string $reference, callable $callback): PointTransaction
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException $e) {
            return $this->findByReference($outlet, $type, $reference) ?? throw $e;
        }
    }

    private function findByReference(Outlet $outlet, string $type, ?string $reference): ?PointTransaction
    {
        if ($reference === null) {
            return null;
        }

        return PointTransaction::where('outlet_id', $outlet->id)
            ->where('type', $type)
            ->where('reference', $reference)
            ->first();
    }
}
