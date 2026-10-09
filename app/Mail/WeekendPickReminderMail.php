<?php

namespace App\Mail;

use App\Models\WeekendPickReminder;
use App\Support\Mail\MailSubject;
use App\Support\Mail\PlanTypeMailTheme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeekendPickReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public WeekendPickReminder $reminder) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: MailSubject::stamp('Coming up: '.$this->reminder->title),
        );
    }

    public function content(): Content
    {
        $this->reminder->loadMissing('recommendation');

        return new Content(
            markdown: 'mail.weekend-pick-reminder',
            with: [
                'reminder' => $this->reminder,
                'theme' => PlanTypeMailTheme::for('weekend'),
                'city' => $this->reminder->recommendation?->city,
            ],
        );
    }
}
