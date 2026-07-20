<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanType extends Model
{
    /** @use HasFactory<\Database\Factories\PlanTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'slug',
        'label',
        'description',
    ];

    public function planSessions(): HasMany
    {
        return $this->hasMany(PlanSession::class);
    }
}
