<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Bawaan Horizon membuka dasbor untuk siapa saja di APP_ENV=local.
     * Di sini selalu lewat gate, sama dengan menu di panel admin.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn ($request) => Gate::check('viewHorizon', [$request->user()]));
    }

    /**
     * Super admin atau peran dengan izin "Buka Laravel Horizon" (View:Horizon).
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => (bool) $user?->canInPanel('View:Horizon'));
    }
}
