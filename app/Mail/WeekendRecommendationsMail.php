<?php

namespace App\Mail;

use App\Models\WeekendRecommendation;
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
        $subject = 'Your weekend picks in '.$this->recommendation->city;

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

    public function content(): Content
    {
        $theme = PlanTypeMailTheme::for('weekend');

        return new Content(
            view: 'mail.weekend-recommendations',
            with: [
                'theme' => $theme,
                'recommendation' => $this->recommendation,
                'items' => $this->recommendation->items ?? [],
            ],
        );
    }
}
