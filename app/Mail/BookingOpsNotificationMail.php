<?php

namespace App\Mail;

use App\Models\Booking;
use App\Support\Mail\MailSubject;
use App\Support\Mail\PlanTypeMailTheme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingOpsNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: MailSubject::stamp('New PLNR booking: '.$this->booking->title),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.booking-ops-notification',
            with: [
                'theme' => PlanTypeMailTheme::brand(),
                'booking' => $this->booking,
            ],
        );
    }
}
