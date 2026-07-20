<?php

namespace App\Mail;

use App\Models\ItineraryStopReminder;
use App\Support\Mail\PlanTypeMailTheme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ItineraryStopReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ItineraryStopReminder $reminder) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Upcoming: '.$this->reminder->stop_name,
        );
    }

    public function content(): Content
    {
        $this->reminder->loadMissing(['planSession.planType']);
        $slug = $this->reminder->plan_type_slug
            ?? $this->reminder->planSession?->planType?->slug;
        $theme = PlanTypeMailTheme::for($slug);

        return new Content(
            view: 'mail.itinerary-stop-reminder',
            with: [
                'reminder' => $this->reminder,
                'theme' => $theme,
                'city' => $this->reminder->planSession?->city,
            ],
        );
    }
}
