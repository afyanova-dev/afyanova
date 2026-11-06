<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application;

use App\Domains\Clinical\Infrastructure\Reader\ClinicalDocumentationDraftReader;
use App\Domains\Clinical\Infrastructure\Writer\ClinicalDocumentationDraftWriter;
use JsonException;

final class ClinicalDocumentationDraftService
{
    public function __construct(
        private readonly ClinicalDocumentationDraftReader $reader,
        private readonly ClinicalDocumentationDraftWriter $writer,
    ) {}

    /**
     * @return array{values: array<string, mixed>, savedAt: string, expiresAt: string}|null
     */
    public function find(
        string $tenantId,
        string $facilityId,
        string $userId,
        string $encounterId,
        string $section,
    ): ?array {
        return $this->reader->find($tenantId, $facilityId, $userId, $encounterId, $section);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{savedAt: string, expiresAt: string}
     *
     * @throws JsonException
     */
    public function save(
        string $tenantId,
        string $facilityId,
        string $userId,
        string $patientId,
        string $encounterId,
        string $section,
        array $values,
    ): array {
        return $this->writer->save(
            $tenantId,
            $facilityId,
            $userId,
            $patientId,
            $encounterId,
            $section,
            $values,
        );
    }

    public function delete(
        string $tenantId,
        string $facilityId,
        string $userId,
        string $encounterId,
        string $section,
    ): void {
        $this->writer->delete($tenantId, $facilityId, $userId, $encounterId, $section);
    }
}
