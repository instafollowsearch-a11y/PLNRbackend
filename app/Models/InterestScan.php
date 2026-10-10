<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InterestScan extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'started_at',
        'finished_at',
        'users_checked',
        'matches_kept',
        'users_skipped',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'users_checked' => 'integer',
            'matches_kept' => 'integer',
            'users_skipped' => 'integer',
        ];
    }

    public function matches(): HasMany
    {
        return $this->hasMany(InterestMatch::class);
    }
}
