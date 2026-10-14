<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Voucher;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class VoucherPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Voucher');
    }

    public function view(AuthUser $authUser, Voucher $voucher): bool
    {
        return $authUser->can('View:Voucher');
    }
}
