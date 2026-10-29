<?php

declare(strict_types=1);

namespace App\Platform\Integration\Contracts\Port;

use App\Platform\Integration\Domain\Model\InboundMessage;

interface InboundTranslatorPort
{
    public function supports(string $connectorFamily, string $eventType): bool;

    /**
     * Translates inbound message into domain command representation.
     *
     * @return array{command_type: string, payload: array<string, mixed>}
     */
    public function translate(InboundMessage $message): array;
}
