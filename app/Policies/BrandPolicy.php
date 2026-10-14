<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Brand;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class BrandPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Brand');
    }

    public function view(AuthUser $authUser, Brand $brand): bool
    {
        return $authUser->can('View:Brand');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Brand');
    }

    public function update(AuthUser $authUser, Brand $brand): bool
    {
        return $authUser->can('Update:Brand');
    }

    public function delete(AuthUser $authUser, Brand $brand): bool
    {
        return $authUser->can('Delete:Brand');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Brand');
    }
}
