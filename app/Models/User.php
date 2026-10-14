<?php

namespace App\Models;

use App\Models\Concerns\DeletesReplacedMedia;
use App\Support\Avatar;
use App\Support\Media;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'phone', 'email', 'google_id', 'birth_date', 'last_latitude', 'last_longitude', 'password'])]
#[Hidden(['password', 'remember_token', 'google_id'])]
class User extends Authenticatable implements FilamentUser, HasAvatar, HasName
{
    /** @use HasFactory<UserFactory> */
    use DeletesReplacedMedia, HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    public const ROLE_MEMBER = 'member';

    public const ROLE_CASHIER = 'cashier';

    public const ROLE_ADMIN = 'admin';

    /** Peran Shield dengan semua izin panel. */
    public const SUPER_ADMIN = 'super_admin';

    /** Nama perangkat yang dikirim aplikasi saat login (device_name). */
    public const DEVICES = [
        'ios-app' => 'iPhone',
        'android-app' => 'Android',
        'web-app' => 'Web',
        // Nama bawaan bila aplikasi tidak mengirim device_name.
        'mobile' => 'Aplikasi',
    ];

    public static function deviceLabel(?string $device): ?string
    {
        return $device === null ? null : (self::DEVICES[$device] ?? $device);
    }

    protected $attributes = [
        'role' => self::ROLE_MEMBER,
        'tier' => 'silver',
        'points_balance' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->member_code ??= static::generateMemberCode();
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'points_balance' => 'integer',
            'last_latitude' => 'float',
            'last_longitude' => 'float',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public static function generateMemberCode(): string
    {
        do {
            $code = 'LC'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        } while (static::where('member_code', $code)->exists());

        return $code;
    }

    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function isStaff(): bool
    {
        return in_array($this->role, [self::ROLE_CASHIER, self::ROLE_ADMIN], true);
    }

    public function isProfileComplete(): bool
    {
        return filled($this->name) && $this->birth_date !== null;
    }

    protected static function mediaColumns(): array
    {
        return ['avatar_path'];
    }

    // --- Panel admin (Filament) ---

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN && $this->hasRole(self::SUPER_ADMIN);
    }

    /** Untuk admin awal (seeder & admin:create). Peran dibuat bila belum ada. */
    public function grantSuperAdmin(): void
    {
        $this->assignRole(Role::findOrCreate(self::SUPER_ADMIN, 'web'));
    }

    /**
     * Izin Shield (mis. "View:Horizon") hanya berlaku untuk akun admin panel —
     * peran yang tertinggal di akun yang sudah dicabut aksesnya tidak membuka apa pun.
     */
    public function canInPanel(string $permission): bool
    {
        return $this->role === self::ROLE_ADMIN && $this->can($permission);
    }

    public function getFilamentName(): string
    {
        return $this->name ?? $this->phone ?? $this->member_code;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return Media::url($this->avatar_path) ?? Avatar::initials($this->getFilamentName());
    }
}
