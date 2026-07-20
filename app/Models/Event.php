<?php

namespace App\Models;

use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source',
        'external_id',
        'title',
        'description',
        'city',
        'venue_name',
        'starts_at',
        'ends_at',
        'url',
        'image_url',
        'price_min',
        'price_max',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'payload' => 'array',
        ];
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeForCity(Builder $query, string $city): void
    {
        $query->where('city', $city);
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where('starts_at', '>=', now());
    }
}
