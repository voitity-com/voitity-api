<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\HasApiTokens;

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
        'locale',
        'email_verified_at',
        'password',
        'google_id',
        'avatar',
        'provider',
        'email_verification_token',
        'email_verification_sent_at',
        'email_verification_expires_at',
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
        'email_verification_token',
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
            'email_verification_sent_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'google_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Return abilities for the specific role
     */
    public function getRoleAbilities(): array
    {
        return Config::get('roles.'.$this->role.'.abilities') ?? [];
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
    public function aiVideos()
    {
        return $this->hasMany(AiVideo::class);
    }

    /**
     * Get the AI images for the user.
     */
    public function aiImages()
    {
        return $this->hasMany(AiImage::class);
    }

    /**
     * Get the profile avatars for the user.
     */
    public function profileAvatars()
    {
        return $this->hasMany(ProfileAvatar::class);
    }

    public function profileSources()
    {
        return $this->hasMany(ProfileSource::class);
    }

    public function profileChats()
    {
        return $this->hasManyThrough(Chat::class, Profile::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)
            ->where('active', true)
            ->latestOfMany('started_at');
    }

    public function subscriptionLimits()
    {
        return $this->hasMany(SubscriptionLimit::class);
    }

    public function subscriptionUses()
    {
        return $this->hasMany(SubscriptionUse::class);
    }

    public function paymentOrders()
    {
        return $this->hasMany(PaymentOrder::class);
    }

    public function paymentSources()
    {
        return $this->hasMany(PaymentSource::class);
    }

    public function loginEvents()
    {
        return $this->hasMany(AuthLoginEvent::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(UserNotificationPreference::class);
    }
}
