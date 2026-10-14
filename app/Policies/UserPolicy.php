<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:User');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:User');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:User');
    }

    public function update(AuthUser $authUser): bool
    {
        return $authUser->can('Update:User');
    }

    /** Hanya akun member/kasir; akun admin panel tidak bisa dihapus dari menu Member. */
    public function delete(AuthUser $authUser, User $user): bool
    {
        return $authUser->can('Delete:User')
            && $user->role !== User::ROLE_ADMIN
            && ! $user->is($authUser);
    }
}
