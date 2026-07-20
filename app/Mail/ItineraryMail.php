<?php

namespace App\Mail;

use App\Models\Itinerary;
use App\Models\PlanSession;
use App\Support\Mail\PlanTypeMailTheme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ItineraryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PlanSession $planSession,
        public Itinerary $itinerary,
    ) {}

    public function envelope(): Envelope
    {
        $title = $this->itinerary->content['title'] ?? 'Your PLNR Itinerary';

        return new Envelope(
            subject: $title,
        );
    }

    public function content(): Content
    {
        $this->planSession->loadMissing('planType');
        $slug = $this->planSession->planType?->slug;
        $theme = PlanTypeMailTheme::for($slug);

        return new Content(
            view: 'mail.itinerary',
            with: [
                'planSession' => $this->planSession,
                'itinerary' => $this->itinerary,
                'content' => $this->itinerary->content ?? [],
                'theme' => $theme,
            ],
        );
    }
}
