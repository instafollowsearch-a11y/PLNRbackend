<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterestPing extends Model
{
    public const KIND_DAY_BEFORE = 'day_before';

    public const KIND_MORNING_OF = 'morning_of';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'event_id',
        'kind',
        'matched_interest',
        'scheduled_at',
        'remind_at',
        'push_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'remind_at' => 'datetime',
            'push_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
