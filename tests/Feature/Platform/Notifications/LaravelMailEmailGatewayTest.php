<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Notifications;

use App\Platform\Notifications\Domain\Port\EmailGatewayPort;
use App\Platform\Notifications\Infrastructure\Adapter\LaravelMailEmailGateway;
use App\Platform\Notifications\Infrastructure\Mail\NotificationMailable;
use Illuminate\Support\Facades\Mail;

test('LaravelMailEmailGateway dispatches email via Laravel Mailer', function (): void {
    Mail::fake();

    config()->set('notifications.email.from_address', 'notifications@afyanova.org');
    config()->set('notifications.email.from_name', 'AfyaNova Health Services');

    $gateway = new LaravelMailEmailGateway;
    $result = $gateway->sendEmail(
        toEmail: 'patient@example.com',
        subject: 'Laboratory Results Ready',
        bodyHtml: '<p>Your test results are available on the portal.</p>',
    );

    expect($result->success)->toBeTrue();
    expect($result->providerMessageId)->toStartWith('MAIL-');

    Mail::assertSent(NotificationMailable::class, function (NotificationMailable $mail): bool {
        return $mail->hasTo('patient@example.com')
            && $mail->subjectLine === 'Laboratory Results Ready'
            && $mail->htmlBody === '<p>Your test results are available on the portal.</p>';
    });
});

test('NotificationsServiceProvider resolves LaravelMailEmailGateway when configured', function (): void {
    config()->set('notifications.email_driver', 'mail');

    $gateway = app(EmailGatewayPort::class);

    expect($gateway)->toBeInstanceOf(LaravelMailEmailGateway::class);
});
