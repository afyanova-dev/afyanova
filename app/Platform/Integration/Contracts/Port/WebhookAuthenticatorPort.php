<?php

declare(strict_types=1);

namespace App\Platform\Integration\Contracts\Port;

use App\Platform\Integration\Domain\Model\IntegrationCredential;

interface WebhookAuthenticatorPort
{
    /**
     * @param  array<string, mixed>  $headers
     */
    public function authenticate(IntegrationCredential $credential, array $headers, string $rawBody): bool;
}
