<?php

declare(strict_types=1);

namespace App\Platform\Integration\Contracts\Fixture;

use App\Platform\Integration\Contracts\Port\InboundTranslatorPort;
use App\Platform\Integration\Domain\Exception\TranslationFailedException;
use App\Platform\Integration\Domain\Model\InboundMessage;

final class SyntheticInboundTranslator implements InboundTranslatorPort
{
    private bool $shouldFail = false;

    public function setShouldFail(bool $fail): void
    {
        $this->shouldFail = $fail;
    }

    public function supports(string $connectorFamily, string $eventType): bool
    {
        return true;
    }

    public function translate(InboundMessage $message): array
    {
        if ($this->shouldFail || ($message->raw_payload['fail_translation'] ?? false) === true) {
            throw new TranslationFailedException('Synthetic translation failure requested');
        }

        return [
            'command_type' => 'RecordSyntheticEvent',
            'payload' => [
                'external_ref' => $message->external_ref,
                'correlation_id' => $message->correlation_id,
                'data' => $message->raw_payload ?? [],
            ],
        ];
    }
}
