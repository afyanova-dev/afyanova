<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Mail;

use Illuminate\Mail\Mailable;

final class NotificationMailable extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $htmlBody,
        ?string $fromAddress = null,
        ?string $fromName = null,
    ) {
        if ($fromAddress !== null) {
            $this->from($fromAddress, $fromName);
        }
        $this->subject($this->subjectLine);
    }

    public function build(): self
    {
        return $this->html($this->htmlBody);
    }
}
