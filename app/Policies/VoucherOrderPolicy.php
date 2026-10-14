<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\VoucherOrder;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class VoucherOrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:VoucherOrder');
    }

    public function view(AuthUser $authUser, VoucherOrder $voucherOrder): bool
    {
        return $authUser->can('View:VoucherOrder');
    }
}
