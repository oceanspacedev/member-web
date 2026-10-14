<?php

namespace App\Filament\Widgets;

use App\Models\Outlet;
use App\Models\PointTransaction;
use App\Models\Promo;
use App\Models\User;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Laravel\Sanctum\PersonalAccessToken;

class LoyaltyStats extends StatsOverviewWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $monthStart = now(config('loyalty.timezone'))->startOfMonth()->utc();
        $members = User::where('role', User::ROLE_MEMBER);
        $thisMonth = PointTransaction::where('created_at', '>=', $monthStart);

        $fmt = fn (int|float $n) => number_format($n, 0, ',', '.');

        return [
            Stat::make('Member', $fmt((clone $members)->count()))
                ->description('+'.$fmt((clone $members)->where('created_at', '>=', $monthStart)->count()).' bulan ini')
                ->color('primary'),
            Stat::make('Aktif di aplikasi hari ini', $fmt((clone $members)->where('last_seen_at', '>=', now(config('loyalty.timezone'))->startOfDay()->utc())->count()))
                ->description($fmt((clone $members)->where('last_seen_at', '>=', now()->subDays(7))->count()).' member dalam 7 hari terakhir')
                ->color('success'),
            Stat::make('Sedang login', $fmt((clone $members)->has('tokens')->count()))
                ->description('di '.$fmt(PersonalAccessToken::where('tokenable_type', (new User)->getMorphClass())->whereIn('tokenable_id', (clone $members)->select('id'))->count()).' perangkat'),
            Stat::make('Belanja tercatat bulan ini', 'Rp'.$fmt((clone $thisMonth)->where('type', PointTransaction::TYPE_EARN)->sum('amount')))
                ->description($fmt((clone $thisMonth)->where('type', PointTransaction::TYPE_EARN)->count()).' transaksi'),
            Stat::make('Poin diberikan bulan ini', $fmt((clone $thisMonth)->where('points', '>', 0)->sum('points')))
                ->description($fmt(abs((clone $thisMonth)->where('points', '<', 0)->sum('points'))).' poin ditukar')
                ->color('success'),
            Stat::make('Promo tayang', $fmt(Promo::running()->count()))
                ->description($fmt(Outlet::where('is_active', true)->count()).' outlet aktif'),
        ];
    }
}
