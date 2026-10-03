<?php

namespace App\Mail;

use App\Models\Itinerary;
use App\Models\PlanSession;
use App\Services\Settings\AppSettings;
use App\Support\Mail\MailSubject;
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
        $title = MailSubject::stamp($this->itinerary->content['title'] ?? 'Your PLNR Itinerary');
        $settings = app(\App\Services\Settings\AppSettings::class);
        $fromAddress = $settings->mailFromAddress();

        if ($fromAddress) {
            return new Envelope(
                subject: $title,
                from: new \Illuminate\Mail\Mailables\Address(
                    $fromAddress,
                    $settings->mailFromName() ?? (string) config('mail.from.name'),
                ),
            );
        }

        return new Envelope(
            subject: $title,
        );
    }

    public function content(): Content
    {
        $this->planSession->loadMissing('planType');
        $slug = $this->planSession->planType?->slug ?? 'night_out';
        $theme = PlanTypeMailTheme::for($slug);
        $webBase = rtrim((string) (app(AppSettings::class)->webAppUrl() ?? ''), '/');
        $viewUrl = $webBase !== ''
            ? $webBase.'/plan/'.$slug.'/itinerary?session='.$this->planSession->uuid
            : null;

        return new Content(
            view: 'mail.view-on-plnr',
            with: [
                'planSession' => $this->planSession,
                'itinerary' => $this->itinerary,
                'content' => $this->itinerary->content ?? [],
                'theme' => $theme,
                'viewUrl' => $viewUrl,
            ],
        );
    }
}
