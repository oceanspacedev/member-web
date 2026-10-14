<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReceiptClaim;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ReceiptClaimPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ReceiptClaim');
    }

    public function view(AuthUser $authUser, ReceiptClaim $receiptClaim): bool
    {
        return $authUser->can('View:ReceiptClaim');
    }

    /** Menyetujui atau menolak klaim. */
    public function update(AuthUser $authUser, ReceiptClaim $receiptClaim): bool
    {
        return $authUser->can('Update:ReceiptClaim');
    }
}
