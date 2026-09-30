<?php

namespace App\Mail;

use App\Services\Settings\AppSettings;
use App\Support\Mail\MailSubject;
use App\Support\Mail\PlanTypeMailTheme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $email,
        public string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        $settings = app(AppSettings::class);
        $fromAddress = $settings->mailFromAddress();
        $subject = MailSubject::stamp('Reset your password');

        if ($fromAddress) {
            return new Envelope(
                subject: $subject,
                from: new Address(
                    $fromAddress,
                    $settings->mailFromName() ?? (string) config('mail.from.name'),
                ),
                to: [$this->email],
            );
        }

        return new Envelope(
            subject: $subject,
            to: [$this->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.reset-password',
            with: [
                'theme' => PlanTypeMailTheme::brand(),
                'email' => $this->email,
                'resetUrl' => $this->resetUrl,
            ],
        );
    }
}
