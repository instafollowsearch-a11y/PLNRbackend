<?php

namespace App\Mail;

use App\Models\Booking;
use App\Support\Mail\PlanTypeMailTheme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reminder: '.$this->booking->title,
        );
    }

    public function content(): Content
    {
        $this->booking->loadMissing(['user', 'planSession.planType']);
        $slug = $this->booking->planSession?->planType?->slug
            ?? ($this->booking->metadata['plan_type'] ?? null);
        $theme = PlanTypeMailTheme::for(is_string($slug) ? $slug : null);

        return new Content(
            view: 'mail.booking-reminder',
            with: [
                'booking' => $this->booking,
                'theme' => $theme,
            ],
        );
    }
}
