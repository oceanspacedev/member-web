<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'birth_date' => $this->birth_date?->toDateString(),
            'avatar_url' => Media::url($this->avatar_path),
            'member_code' => $this->member_code,
            'member_code_display' => trim(chunk_split($this->member_code, 4, ' ')),
            'role' => $this->role,
            'outlet_id' => $this->outlet_id,
            'tier' => $this->tier,
            'points_balance' => $this->points_balance,
            'profile_complete' => $this->isProfileComplete(),
        ];
    }
}
