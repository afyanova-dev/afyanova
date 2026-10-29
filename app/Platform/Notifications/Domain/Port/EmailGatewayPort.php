<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Port;

interface EmailGatewayPort
{
    public function sendEmail(string $toEmail, string $subject, string $bodyHtml): GatewayResultDto;
}
