<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class AdminBootstrapInvitation extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set up your AfyaNova account');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.admin-bootstrap-invitation',
            with: ['setupUrl' => route('admin.bootstrap.form').'#'.$this->token],
        );
    }
}
