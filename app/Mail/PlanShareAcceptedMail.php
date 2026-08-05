<?php

namespace App\Mail;

use App\Models\PlanShare;
use App\Models\User;
use App\Support\Mail\PlanTypeMailTheme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlanShareAcceptedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PlanShare $share,
        public User $acceptedBy,
    ) {}

    public function envelope(): Envelope
    {
        $settings = app(\App\Services\Settings\AppSettings::class);
        $fromAddress = $settings->mailFromAddress();
        $subject = $this->acceptedBy->name.' accepted your plan invite';

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
        $slug = $this->share->planSession?->planType?->slug;

        return new Content(
            view: 'mail.plan-share-accepted',
            with: [
                'theme' => PlanTypeMailTheme::for($slug ?? 'share'),
                'share' => $this->share,
                'acceptedBy' => $this->acceptedBy,
            ],
        );
    }
}
