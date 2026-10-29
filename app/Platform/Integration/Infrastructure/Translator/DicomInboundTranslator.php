<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Translator;

use App\Platform\Integration\Contracts\Port\InboundTranslatorPort;
use App\Platform\Integration\Domain\Enum\ConnectorFamily;
use App\Platform\Integration\Domain\Exception\TranslationFailedException;
use App\Platform\Integration\Domain\Model\InboundMessage;

final class DicomInboundTranslator implements InboundTranslatorPort
{
    public function supports(string $connectorFamily, string $eventType): bool
    {
        if ($connectorFamily === ConnectorFamily::DIAGNOSTIC_ANALYZER->value) {
            return str_starts_with(strtoupper($eventType), 'DICOM')
                || str_starts_with(strtoupper($eventType), 'PACS')
                || in_array(strtoupper($eventType), ['IMAGING_STUDY', 'C_STORE_NOTIFICATION'], true);
        }

        return false;
    }

    /**
     * @return array{command_type: string, payload: array<string, mixed>}
     */
    public function translate(InboundMessage $message): array
    {
        $raw = $message->raw_payload ?? [];

        $studyUid = (string) ($raw['StudyInstanceUID'] ?? $raw['study_instance_uid'] ?? $raw['ID'] ?? '');
        if ($studyUid === '') {
            throw new TranslationFailedException('DICOM payload missing StudyInstanceUID');
        }

        $accessionNumber = (string) ($raw['AccessionNumber'] ?? $raw['accession_number'] ?? $raw['MainDicomTags']['AccessionNumber'] ?? $message->correlation_id);
        $patientId = (string) ($raw['PatientID'] ?? $raw['patient_id'] ?? $raw['MainDicomTags']['PatientID'] ?? '');
        $modality = (string) ($raw['Modality'] ?? $raw['modality'] ?? $raw['MainDicomTags']['Modality'] ?? 'DX');
        $studyDate = (string) ($raw['StudyDate'] ?? $raw['study_date'] ?? $raw['MainDicomTags']['StudyDate'] ?? date('Ymd'));
        $seriesCount = (int) ($raw['SeriesCount'] ?? count($raw['Series'] ?? []) ?: 1);
        $instancesCount = (int) ($raw['InstancesCount'] ?? count($raw['Instances'] ?? []) ?: 1);

        $wadoUrl = (string) ($raw['WadoUrl'] ?? $raw['wado_url'] ?? "/dicom-web/studies/{$studyUid}");

        return [
            'command_type' => 'RecordExternalImagingStudy',
            'payload' => [
                'study_instance_uid' => $studyUid,
                'accession_number' => $accessionNumber,
                'patient_identifier' => $patientId,
                'modality' => $modality,
                'series_count' => $seriesCount,
                'instances_count' => $instancesCount,
                'study_date' => $studyDate,
                'wado_url' => $wadoUrl,
                'raw_ref' => $message->external_ref,
            ],
        ];
    }
}
