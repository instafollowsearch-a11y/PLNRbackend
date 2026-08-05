<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanMember extends Model
{
    public const ROLE_OWNER = 'owner';

    public const ROLE_VIEWER = 'viewer';

    protected $fillable = [
        'plan_session_id',
        'user_id',
        'role',
        'plan_share_id',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    public function planSession(): BelongsTo
    {
        return $this->belongsTo(PlanSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function planShare(): BelongsTo
    {
        return $this->belongsTo(PlanShare::class);
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }
}
