<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageVisit extends Model
{
    public const PLAN_GUEST = 'guest';

    public const PLAN_FREE = 'free';

    public const PLAN_PRO = 'pro';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'occurred_at',
        'path',
        'referrer',
        'ip_address',
        'user_agent',
        'browser',
        'browser_version',
        'platform',
        'platform_version',
        'device',
        'device_type',
        'is_robot',
        'language',
        'timezone',
        'screen',
        'plan',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'is_robot' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
