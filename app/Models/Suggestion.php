<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Suggestion extends Model
{
    /** @use HasFactory<\Database\Factories\SuggestionFactory> */
    use HasFactory;

    protected $fillable = [
        'plan_session_id',
        'payload',
        'selected_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'selected_at' => 'datetime',
        ];
    }

    public function planSession(): BelongsTo
    {
        return $this->belongsTo(PlanSession::class);
    }
}
