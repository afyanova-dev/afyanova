<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Translator;

use App\Platform\Integration\Contracts\Port\InboundTranslatorPort;
use App\Platform\Integration\Domain\Enum\ConnectorFamily;
use App\Platform\Integration\Domain\Exception\TranslationFailedException;
use App\Platform\Integration\Domain\Model\InboundMessage;

final class Hl7InboundTranslator implements InboundTranslatorPort
{
    public function supports(string $connectorFamily, string $eventType): bool
    {
        if ($connectorFamily === ConnectorFamily::DIAGNOSTIC_ANALYZER->value) {
            return ! str_starts_with(strtoupper($eventType), 'DICOM')
                && ! str_starts_with(strtoupper($eventType), 'PACS')
                && ! in_array(strtoupper($eventType), ['IMAGING_STUDY', 'C_STORE_NOTIFICATION'], true);
        }

        return str_starts_with(strtoupper($eventType), 'HL7')
            || in_array(strtoupper($eventType), ['ORU_R01', 'LAB_RESULT', 'ANALYZER_RESULT'], true);
    }

    /**
     * @return array{command_type: string, payload: array<string, mixed>}
     */
    public function translate(InboundMessage $message): array
    {
        $raw = $message->raw_payload ?? [];

        // Support both structured JSON or raw HL7 text message
        $hl7Text = $raw['hl7_message'] ?? $raw['raw_text'] ?? null;

        if (is_string($hl7Text)) {
            return $this->parseHl7PipeDelimited($hl7Text, $message);
        }

        if (isset($raw['segments']) && is_array($raw['segments'])) {
            return $this->parseHl7Segments($raw['segments'], $message);
        }

        if (isset($raw['observations']) && is_array($raw['observations'])) {
            return [
                'command_type' => 'ImportExternalLabResult',
                'payload' => [
                    'message_control_id' => (string) ($raw['message_control_id'] ?? $message->external_ref),
                    'patient_identifier' => (string) ($raw['patient_identifier'] ?? $raw['patient_id'] ?? ''),
                    'order_reference' => (string) ($raw['order_reference'] ?? $message->correlation_id),
                    'observations' => $raw['observations'],
                    'external_ref' => $message->external_ref,
                ],
            ];
        }

        throw new TranslationFailedException('HL7 payload missing recognized message structure (hl7_message string or segments array)');
    }

    /**
     * @return array{command_type: string, payload: array<string, mixed>}
     */
    private function parseHl7PipeDelimited(string $text, InboundMessage $message): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($text));
        if ($lines === false || empty($lines)) {
            throw new TranslationFailedException('HL7 message is empty');
        }

        $segments = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $parts = explode('|', $trimmed);
            $segName = $parts[0];
            $segments[$segName][] = $parts;
        }

        return $this->parseHl7SegmentMap($segments, $message);
    }

    /**
     * @param  array<mixed>  $rawSegments
     * @return array{command_type: string, payload: array<string, mixed>}
     */
    private function parseHl7Segments(array $rawSegments, InboundMessage $message): array
    {
        /** @var array<string, list<list<string>>> $map */
        $map = [];
        foreach ($rawSegments as $seg) {
            if (is_array($seg) && isset($seg[0]) && is_scalar($seg[0])) {
                $name = (string) $seg[0];
                /** @var list<string> $stringSeg */
                $stringSeg = array_map('strval', $seg);
                $map[$name][] = $stringSeg;
            }
        }

        return $this->parseHl7SegmentMap($map, $message);
    }

    /**
     * @param  array<string, list<list<string>>>  $segments
     * @return array{command_type: string, payload: array<string, mixed>}
     */
    private function parseHl7SegmentMap(array $segments, InboundMessage $message): array
    {
        $msh = $segments['MSH'][0] ?? null;
        if ($msh === null) {
            throw new TranslationFailedException('HL7 message missing required MSH segment');
        }

        // MSH-10 is message control ID. Note in MSH, field index starts after MSH|
        $messageControlId = $msh[9] ?? $message->external_ref;

        $pid = $segments['PID'][0] ?? [];
        $patientId = $pid[3] ?? '';

        $obr = $segments['OBR'][0] ?? [];
        $orderRef = $obr[2] ?? $obr[3] ?? $message->correlation_id;

        $obxList = $segments['OBX'] ?? [];
        $observations = [];

        foreach ($obxList as $obx) {
            $identifier = $obx[3] ?? '';
            $value = $obx[5] ?? '';
            $units = $obx[6] ?? '';
            $range = $obx[7] ?? '';
            $flags = $obx[8] ?? 'N';
            $status = $obx[11] ?? 'F';

            $observations[] = [
                'identifier' => $identifier,
                'value' => $value,
                'units' => $units,
                'reference_range' => $range,
                'abnormal_flag' => $flags,
                'result_status' => $status,
            ];
        }

        return [
            'command_type' => 'ImportExternalLabResult',
            'payload' => [
                'message_control_id' => (string) $messageControlId,
                'patient_identifier' => (string) $patientId,
                'order_reference' => (string) $orderRef,
                'observations' => $observations,
                'external_ref' => $message->external_ref,
            ],
        ];
    }
}
