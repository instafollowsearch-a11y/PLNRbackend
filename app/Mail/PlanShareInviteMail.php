<?php

namespace App\Mail;

use App\Models\PlanShare;
use App\Support\Mail\PlanTypeMailTheme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlanShareInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{web: ?string, app: string}  $urls
     */
    public function __construct(
        public PlanShare $share,
        public array $urls,
    ) {}

    public function envelope(): Envelope
    {
        $inviter = $this->share->inviter?->name ?? 'Someone';
        $settings = app(\App\Services\Settings\AppSettings::class);
        $fromAddress = $settings->mailFromAddress();
        $subject = $inviter.' shared a PLNR plan with you';

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
        $theme = PlanTypeMailTheme::for($slug ?? 'share');
        $settings = app(\App\Services\Settings\AppSettings::class);

        return new Content(
            view: 'mail.plan-share-invite',
            with: [
                'theme' => $theme,
                'share' => $this->share,
                'urls' => $this->urls,
                'appStoreUrl' => $settings->appStoreUrl(),
                'playStoreUrl' => $settings->playStoreUrl(),
            ],
        );
    }
}
