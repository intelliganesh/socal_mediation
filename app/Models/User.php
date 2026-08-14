<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'application',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isGlobalAdmin(): bool
    {
        return $this->role === 'admin' && $this->application === null;
    }

    public function canAccessApplication(?string $application): bool
    {
        return $this->isGlobalAdmin() || ($application !== null && $this->application === $application);
    }

    public function allowedApplications(): array
    {
        return $this->isGlobalAdmin() ? ['socal', 'legal'] : array_values(array_filter([$this->application]));
    }

    public function applyApplicationScope($query, string $column = 'application')
    {
        if (! $this->isGlobalAdmin()) {
            $query->where($column, $this->application);
        }

        return $query;
    }
}
