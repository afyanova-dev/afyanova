<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Translator;

use App\Platform\Integration\Contracts\Fixture\SyntheticInboundTranslator;
use App\Platform\Integration\Contracts\Port\InboundTranslatorPort;
use App\Platform\Integration\Domain\Model\InboundMessage;

final class CompositeInboundTranslator implements InboundTranslatorPort
{
    public function __construct(
        private readonly MpesaInboundTranslator $mpesaTranslator,
        private readonly Hl7InboundTranslator $hl7Translator,
        private readonly DicomInboundTranslator $dicomTranslator,
        private readonly SyntheticInboundTranslator $syntheticTranslator,
    ) {}

    public function supports(string $connectorFamily, string $eventType): bool
    {
        return true;
    }

    /**
     * @return array{command_type: string, payload: array<string, mixed>}
     */
    public function translate(InboundMessage $message): array
    {
        $connector = $message->connector;
        $family = $connector?->family->value ?? 'OTHER';
        $raw = $message->raw_payload ?? [];
        $eventType = (string) ($raw['event_type'] ?? $raw['EventType'] ?? $raw['type'] ?? '');

        // 1. DICOM / PACS Imaging
        if ($this->dicomTranslator->supports($family, $eventType)
            || isset($raw['StudyInstanceUID'])
            || isset($raw['study_instance_uid'])
            || isset($raw['Modality'])
            || isset($raw['AccessionNumber'])
            || isset($raw['WadoUrl'])
        ) {
            return $this->dicomTranslator->translate($message);
        }

        // 2. M-Pesa
        if ($this->mpesaTranslator->supports($family, $eventType)
            || isset($raw['Body']['stkCallback'])
            || isset($raw['TransID'])
        ) {
            return $this->mpesaTranslator->translate($message);
        }

        // 3. HL7 Laboratory
        if ($this->hl7Translator->supports($family, $eventType)
            || isset($raw['hl7_message'])
            || isset($raw['raw_text'])
            || (isset($raw['segments']) && is_array($raw['segments']))
            || (isset($raw['observations']) && is_array($raw['observations']) && ! isset($raw['fail_translation']))
        ) {
            return $this->hl7Translator->translate($message);
        }

        // 4. Fallback to synthetic double for tests or generic payloads
        return $this->syntheticTranslator->translate($message);
    }
}
