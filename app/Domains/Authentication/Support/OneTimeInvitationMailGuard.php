<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Support;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;

final class OneTimeInvitationMailGuard
{
    public static function canDeliver(): bool
    {
        if (app()->environment('testing') && Mail::getFacadeRoot() instanceof MailFake) {
            return true;
        }

        return self::isSafeMailer((string) config('mail.default'), []);
    }

    /**
     * @param  list<string>  $visited
     */
    private static function isSafeMailer(string $name, array $visited): bool
    {
        if (in_array($name, $visited, true)) {
            return false;
        }
        $visited[] = $name;
        $mailer = config("mail.mailers.{$name}");
        if (! is_array($mailer)) {
            return false;
        }

        $transport = $mailer['transport'] ?? $name;
        if (in_array($transport, ['log', 'array'], true)) {
            return false;
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $mailers = $mailer['mailers'] ?? null;

            return is_array($mailers)
                && $mailers !== []
                && collect($mailers)->every(fn (mixed $fallback): bool => is_string($fallback)
                    && self::isSafeMailer($fallback, $visited));
        }

        if ($transport === 'smtp') {
            return is_string($mailer['host'] ?? null)
                && trim($mailer['host']) !== ''
                && is_numeric($mailer['port'] ?? null)
                && is_string($mailer['username'] ?? null)
                && trim($mailer['username']) !== ''
                && is_string($mailer['password'] ?? null)
                && trim($mailer['password']) !== ''
                && is_array(config('mail.from'))
                && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
        }

        return in_array($transport, ['sendmail', 'mailgun', 'ses', 'ses-v2', 'postmark', 'resend'], true);
    }
}
