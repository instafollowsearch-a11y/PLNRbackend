<?php

namespace App\Mail;

use App\Models\WeekendRecommendation;
use App\Support\Mail\MailSubject;
use App\Support\Mail\PlanTypeMailTheme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeekendRecommendationsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WeekendRecommendation $recommendation,
    ) {}

    public function envelope(): Envelope
    {
        $settings = app(\App\Services\Settings\AppSettings::class);
        $fromAddress = $settings->mailFromAddress();
        $subject = MailSubject::stamp('Your weekend picks in '.$this->recommendation->city);

        if ($fromAddress) {
            return new Envelope(
                subject: $subject,
                from: new \Illuminate\Mail\Mailables\Address(
                    $fromAddress,
                    $settings->mailFromName() ?? (string) config('mail.from.name'),
                ),
            );
        }

        return new Envelope(subject: $subject);
    }

    /**
     * @return array<string, list<array<string, mixed>>>|null
     */
    private function days(): ?array
    {
        $grouped = [
            'Friday' => [],
            'Saturday' => [],
            'Sunday' => [],
        ];
        $hasWeekendDay = false;

        foreach ($this->recommendation->items ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $day = $item['day'] ?? null;

            if (! is_string($day) || ! array_key_exists($day, $grouped)) {
                continue;
            }

            $grouped[$day][] = $item;
            $hasWeekendDay = true;
        }

        return $hasWeekendDay ? $grouped : null;
    }

    public function content(): Content
    {
        $theme = PlanTypeMailTheme::for('weekend');

        return new Content(
            view: 'mail.weekend-recommendations',
            with: [
                'theme' => $theme,
                'recommendation' => $this->recommendation,
                'items' => $this->recommendation->items ?? [],
                'days' => $this->days(),
                'saturdayPlan' => is_array($this->recommendation->saturday_plan)
                    ? $this->recommendation->saturday_plan
                    : null,
            ],
        );
    }
}
