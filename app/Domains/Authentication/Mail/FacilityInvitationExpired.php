<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * HR-facing alert for an expired facility setup invitation.
 *
 * This mail deliberately contains no token or setup URL. The recipient is an
 * administrator, not the invitee, and the recovery action is to open the
 * invitation worklist and resend or revoke it.
 */
final class FacilityInvitationExpired extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $inviteeName,
        public readonly string $inviteeEmail,
        public readonly string $facilityName,
        public readonly string $expiredAt,
        public readonly string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Staff setup invitation expired — '.$this->facilityName);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.facility-invitation-expired',
            with: [
                'inviteeName' => $this->inviteeName,
                'inviteeEmail' => $this->inviteeEmail,
                'facilityName' => $this->facilityName,
                'expiredAt' => $this->expiredAt,
                'actionUrl' => $this->actionUrl,
            ],
        );
    }
}
