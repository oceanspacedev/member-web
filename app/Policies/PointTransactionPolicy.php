<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PointTransaction;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PointTransactionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PointTransaction');
    }

    public function view(AuthUser $authUser, PointTransaction $pointTransaction): bool
    {
        return $authUser->can('View:PointTransaction');
    }
}
