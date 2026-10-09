<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeekendPickReminder extends Model
{
    protected $fillable = [
        'weekend_recommendation_id',
        'user_id',
        'item_index',
        'title',
        'scheduled_at',
        'remind_at',
        'recipient_email',
        'email_sent_at',
        'push_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'remind_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'push_sent_at' => 'datetime',
        ];
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(WeekendRecommendation::class, 'weekend_recommendation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
