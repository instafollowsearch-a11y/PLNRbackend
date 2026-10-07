<?php

namespace App\Models;

use App\Services\Pro\ProAccess;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    public const ROLE_USER = 'user';

    public const ROLE_ADMIN = 'admin';

    public const PRO_STATUS_INACTIVE = 'inactive';

    public const PRO_STATUS_ACTIVE = 'active';

    public const PRO_STATUS_PAST_DUE = 'past_due';

    public const PRO_STATUS_CANCELED = 'canceled';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'city',
        'interests',
        'stripe_customer_id',
        'stripe_subscription_id',
        'pro_status',
        'pro_current_period_end',
        'google_play_product_id',
        'google_play_purchase_token',
        'apple_product_id',
        'apple_original_transaction_id',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'interests' => 'array',
            'pro_current_period_end' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isPro(): bool
    {
        return app(ProAccess::class)->isActive($this);
    }

    public function hasUnexpiredStoreSubscription(): bool
    {
        $hasStore = filled($this->google_play_purchase_token) || filled($this->apple_original_transaction_id);
        if (! $hasStore) {
            return false;
        }

        if ($this->pro_current_period_end === null) {
            return true;
        }

        return $this->pro_current_period_end->isFuture();
    }

    public function planSessions(): HasMany
    {
        return $this->hasMany(PlanSession::class);
    }

    public function pageVisits(): HasMany
    {
        return $this->hasMany(PageVisit::class);
    }

    public function planMemberships(): HasMany
    {
        return $this->hasMany(PlanMember::class);
    }

    public function weekendRecommendations(): HasMany
    {
        return $this->hasMany(WeekendRecommendation::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function defaultPaymentMethod(): HasOne
    {
        return $this->hasOne(PaymentMethod::class)->where('is_default', true);
    }

    public function devicePushTokens(): HasMany
    {
        return $this->hasMany(DevicePushToken::class);
    }
}
