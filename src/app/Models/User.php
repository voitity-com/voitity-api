<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Config;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role',
        'name',
        'first_name',
        'last_name',
        'email',
        'password',
        'google_id',
        'avatar',
        'provider',
        'google_verified_at',
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
            'google_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Return abilities for the specific role
     *
     * @return array
     */
    public function getRoleAbilities(): array
    {
        return Config::get('roles.' . $this->role . '.abilities') ?? [];
    }
    
    /**
     * Get the profiles for the user.
     */
    public function profiles()
    {
        return $this->hasMany(Profile::class);
    }
    
    /**
     * Get the voices for the user.
     */
    public function voices()
    {
        return $this->hasMany(Voice::class);
    }

    /**
     * Get the AI videos for the user.
     */
    public function videoAIs()
    {
        return $this->hasMany(VideoAI::class);
    }
}
