<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Adapter;

use App\Platform\Notifications\Domain\Port\EmailGatewayPort;
use App\Platform\Notifications\Domain\Port\GatewayResultDto;
use App\Platform\Notifications\Infrastructure\Mail\NotificationMailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class LaravelMailEmailGateway implements EmailGatewayPort
{
    public function sendEmail(string $toEmail, string $subject, string $bodyHtml): GatewayResultDto
    {
        $mailer = config('notifications.email.mailer');
        $fromAddress = (string) config('notifications.email.from_address', 'noreply@afyanova.org');
        $fromName = (string) config('notifications.email.from_name', 'AfyaNova Health');

        try {
            $mailable = new NotificationMailable(
                subjectLine: $subject,
                htmlBody: $bodyHtml,
                fromAddress: $fromAddress,
                fromName: $fromName,
            );

            $mailInstance = $mailer ? Mail::mailer((string) $mailer) : Mail::mailer();
            $mailInstance->to($toEmail)->send($mailable);

            $providerMessageId = 'MAIL-'.Str::uuid()->toString();

            Log::info("LaravelMailEmailGateway: Email sent to [{$toEmail}] with subject [{$subject}]");

            return GatewayResultDto::ok($providerMessageId);
        } catch (Throwable $e) {
            Log::error("LaravelMailEmailGateway: Failed to send email to [{$toEmail}]: {$e->getMessage()}");

            return GatewayResultDto::fail(
                errorCode: 'EMAIL_SEND_FAILED',
                errorMessage: $e->getMessage(),
            );
        }
    }
}
