<?php

namespace App\Models;

use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FAILED = 'failed';

    public const FULFILLMENT_FEE_COLLECTED = 'fee_collected';

    public const FULFILLMENT_PENDING_MANUAL = 'pending_manual';

    public const FULFILLMENT_FULFILLED = 'fulfilled';

    public const FULFILLMENT_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'user_id',
        'plan_session_id',
        'title',
        'amount_cents',
        'currency',
        'status',
        'fulfillment_status',
        'stripe_payment_intent_id',
        'scheduled_for',
        'reminder_sent_at',
        'push_reminder_sent_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'push_reminder_sent_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking): void {
            if (empty($booking->uuid)) {
                $booking->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function planSession(): BelongsTo
    {
        return $this->belongsTo(PlanSession::class);
    }
}
