<?php

namespace App\Mail;

use App\Models\Itinerary;
use App\Models\PlanSession;
use App\Models\User;
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
        $subject = ($this->sharedBy?->name ?? 'A friend').' shared: '.$title;

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
        $slug = $this->planSession->planType?->slug;

        return new Content(
            view: 'mail.itinerary',
            with: [
                'theme' => PlanTypeMailTheme::for($slug),
                'planSession' => $this->planSession,
                'content' => $this->itinerary->content ?? [],
            ],
        );
    }
}
