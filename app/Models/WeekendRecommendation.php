<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WeekendRecommendation extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'city',
        'interests',
        'window_start',
        'window_end',
        'items',
        'email_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'interests' => 'array',
            'items' => 'array',
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'email_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WeekendRecommendation $recommendation): void {
            if ($recommendation->uuid === null || $recommendation->uuid === '') {
                $recommendation->uuid = (string) Str::uuid();
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
}
