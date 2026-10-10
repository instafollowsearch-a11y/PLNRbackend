<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterestMatch extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'interest_scan_id',
        'user_id',
        'event_id',
        'matched_interest',
        'score',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(InterestScan::class, 'interest_scan_id');
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
