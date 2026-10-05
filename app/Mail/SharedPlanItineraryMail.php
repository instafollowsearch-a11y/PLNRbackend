<?php

namespace App\Mail;

use App\Models\Itinerary;
use App\Models\PlanSession;
use App\Models\User;
use App\Services\Settings\AppSettings;
use App\Support\Mail\MailSubject;
use App\Support\Mail\PlanTypeMailTheme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SharedPlanItineraryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PlanSession $planSession,
        public Itinerary $itinerary,
        public ?User $sharedBy,
    ) {}

    public function envelope(): Envelope
    {
        $settings = app(\App\Services\Settings\AppSettings::class);
        $fromAddress = $settings->mailFromAddress();
        $title = $this->itinerary->content['title'] ?? 'Shared PLNR plan';
        $subject = MailSubject::stamp(($this->sharedBy?->name ?? 'A friend').' shared: '.$title);

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
        $this->planSession->loadMissing('planType');
        $slug = $this->planSession->planType?->slug ?? 'night_out';
        $webBase = rtrim((string) (app(AppSettings::class)->webAppUrl() ?? ''), '/');
        $viewUrl = $webBase !== ''
            ? $webBase.'/plan/'.$slug.'/itinerary?session='.$this->planSession->uuid
            : null;

        return new Content(
            view: 'mail.shared-plan-itinerary',
            with: [
                'theme' => PlanTypeMailTheme::for($slug),
                'planSession' => $this->planSession,
                'content' => $this->itinerary->content ?? [],
                'sharedBy' => $this->sharedBy,
                'viewUrl' => $viewUrl,
            ],
        );
    }
}
