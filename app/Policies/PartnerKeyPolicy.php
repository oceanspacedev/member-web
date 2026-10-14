<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PartnerKey;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PartnerKeyPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PartnerKey');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PartnerKey');
    }

    /** Mencabut key. */
    public function update(AuthUser $authUser, PartnerKey $partnerKey): bool
    {
        return $authUser->can('Update:PartnerKey');
    }
}
