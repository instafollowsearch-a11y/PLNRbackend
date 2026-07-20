<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Itinerary extends Model
{
    /** @use HasFactory<\Database\Factories\ItineraryFactory> */
    use HasFactory;

    protected $fillable = [
        'plan_session_id',
        'content',
        'email_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'email_sent_at' => 'datetime',
        ];
    }

    public function planSession(): BelongsTo
    {
        return $this->belongsTo(PlanSession::class);
    }
}
