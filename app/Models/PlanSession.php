<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class PlanSession extends Model
{
    /** @use HasFactory<\Database\Factories\PlanSessionFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    public const STATUS_SUGGESTIONS = 'suggestions';

    public const STATUS_SELECTED = 'selected';

    public const STATUS_ITINERARY = 'itinerary';

    public const STATUS_COMPLETED = 'completed';

    public const MAX_REFINEMENTS = 5;

    protected $fillable = [
        'uuid',
        'user_id',
        'creator_ip',
        'plan_type_id',
        'status',
        'city',
        'recipient_phone',
        'recipient_email',
        'answers',
        'refinement_messages',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'refinement_messages' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PlanSession $session): void {
            if (empty($session->uuid)) {
                $session->uuid = (string) Str::uuid();
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

    public function planType(): BelongsTo
    {
        return $this->belongsTo(PlanType::class);
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(Suggestion::class);
    }

    public function itinerary(): HasOne
    {
        return $this->hasOne(Itinerary::class);
    }

    public function booking(): HasOne
    {
        return $this->hasOne(Booking::class);
    }

    public function stopReminders(): HasMany
    {
        return $this->hasMany(ItineraryStopReminder::class);
    }

    public function markStatus(string $status): void
    {
        $this->update(['status' => $status]);
    }

    public function addRefinementMessage(string $content): void
    {
        $messages = $this->refinement_messages ?? [];
        $messages[] = [
            'role' => 'user',
            'content' => $content,
            'created_at' => now()->toIso8601String(),
        ];

        $this->update(['refinement_messages' => $messages]);
    }

    public function refinementCount(): int
    {
        return count($this->refinement_messages ?? []);
    }

    public function selectedSuggestion(): ?Suggestion
    {
        return $this->suggestions()->whereNotNull('selected_at')->first();
    }
}
