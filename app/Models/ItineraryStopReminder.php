<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItineraryStopReminder extends Model
{
    /** @use HasFactory<\Database\Factories\ItineraryStopReminderFactory> */
    use HasFactory;

    protected $fillable = [
        'plan_session_id',
        'itinerary_id',
        'booking_id',
        'stop_index',
        'day_index',
        'stop_name',
        'activity',
        'notes',
        'scheduled_at',
        'remind_at',
        'recipient_email',
        'plan_type_slug',
        'email_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'remind_at' => 'datetime',
            'email_sent_at' => 'datetime',
        ];
    }

    public function planSession(): BelongsTo
    {
        return $this->belongsTo(PlanSession::class);
    }

    public function itinerary(): BelongsTo
    {
        return $this->belongsTo(Itinerary::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
