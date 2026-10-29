<?php

declare(strict_types=1);

use App\Platform\Documents\Application\Query\GetDownloadStreamQuery;
use App\Platform\Documents\Contracts\DocumentsApi;
use App\Platform\Documents\Contracts\DTO\UploadDocumentDto;
use App\Platform\Documents\Domain\Enum\ConfidentialityLevel;
use App\Platform\Documents\Domain\Enum\DocumentKind;
use App\Platform\Documents\Domain\Enum\DocumentStatus;
use App\Platform\Documents\Domain\Enum\DocumentType;
use App\Platform\Documents\Domain\Exception\DocumentNotAvailableException;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/documents.php';

beforeEach(function (): void {
    Storage::fake('local');
});

it('manages complete upload lifecycle: upload -> scan -> available -> stream download', function (): void {
    $fx = documentsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $api = app(DocumentsApi::class);
    $queryBus = app(QueryBus::class);

    $fileContent = 'AfyaNova Test Medical Report Content - 12345';
    $dto = new UploadDocumentDto(
        tenantId: $tenantId,
        facilityId: $facilityId,
        docType: DocumentType::LAB_REPORT,
        confidentialityLevel: ConfidentialityLevel::STANDARD,
        sourceDomain: 'Laboratory',
        sourceEntityRef: 'LAB-ORD-999',
        fileContents: $fileContent,
        mimeType: 'text/plain',
        fileName: 'report.txt',
        actorId: $userId,
        patientId: (string) Str::uuid(),
    );

    $docDto = TenantContextScope::run($tenantId, $facilityId, fn () => $api->upload($dto));

    expect($docDto->kind)->toBe(DocumentKind::UPLOAD);
    expect($docDto->status)->toBe(DocumentStatus::AVAILABLE);
    expect($docDto->versions)->toHaveCount(1);

    $version = $docDto->versions[0];
    expect($version->versionNo)->toBe(1);
    expect($version->contentHash)->toBe(hash('sha256', $fileContent));
    expect($version->status)->toBe(DocumentStatus::AVAILABLE);

    // Retrieve download url
    $downloadUrlDto = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getDownloadUrl($docDto->id, 1));
    expect($downloadUrlDto->url)->toContain("/api/v1/documents/{$docDto->id}/download?token=");

    // Stream download using actor credentials
    $stream = TenantContextScope::run($tenantId, $facilityId, fn () => $queryBus->dispatch(new GetDownloadStreamQuery(
        tenantId: $tenantId,
        documentId: $docDto->id,
        versionNo: 1,
        actorId: $userId,
    )));

    expect($stream->contents)->toBe($fileContent);
    expect($stream->contentHash)->toBe(hash('sha256', $fileContent));
});

it('fails closed and quarantines infected uploads', function (): void {
    $fx = documentsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $api = app(DocumentsApi::class);
    $queryBus = app(QueryBus::class);

    // Standard EICAR signature test content
    $infectedContent = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    $dto = new UploadDocumentDto(
        tenantId: $tenantId,
        facilityId: $facilityId,
        docType: DocumentType::CLAIM_ATTACHMENT,
        confidentialityLevel: ConfidentialityLevel::STANDARD,
        sourceDomain: 'Insurance',
        sourceEntityRef: 'CLAIM-100',
        fileContents: $infectedContent,
        mimeType: 'text/plain',
        fileName: 'eicar.txt',
        actorId: $userId,
    );

    $docDto = TenantContextScope::run($tenantId, $facilityId, fn () => $api->upload($dto));

    expect($docDto->status)->toBe(DocumentStatus::QUARANTINED);
    expect($docDto->versions[0]->status)->toBe(DocumentStatus::QUARANTINED);

    // Attempt download stream — fails closed
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => $queryBus->dispatch(new GetDownloadStreamQuery(
        tenantId: $tenantId,
        documentId: $docDto->id,
        versionNo: 1,
        actorId: $userId,
    ))))->toThrow(DocumentNotAvailableException::class);
});

it('supports redacting documents creating sequential immutable versions', function (): void {
    $fx = documentsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $api = app(DocumentsApi::class);

    $originalContent = 'Confidential patient notes: John Doe was diagnosed with Condition X.';
    $dto = new UploadDocumentDto(
        tenantId: $tenantId,
        facilityId: $facilityId,
        docType: DocumentType::DISCHARGE_SUMMARY,
        confidentialityLevel: ConfidentialityLevel::STANDARD,
        sourceDomain: 'Clinical',
        sourceEntityRef: 'ENC-001',
        fileContents: $originalContent,
        mimeType: 'text/plain',
        fileName: 'discharge.txt',
        actorId: $userId,
    );

    $docDto = TenantContextScope::run($tenantId, $facilityId, fn () => $api->upload($dto));
    $v1Id = $docDto->versions[0]->id;

    $redactedContent = 'Confidential patient notes: [REDACTED] was diagnosed with Condition X.';
    $v2Dto = TenantContextScope::run($tenantId, $facilityId, fn () => $api->redactVersion(
        versionId: $v1Id,
        redactedContents: $redactedContent,
        reason: 'Patient identity anonymization for research',
        actorId: $userId,
    ));

    expect($v2Dto->versionNo)->toBe(2);
    expect($v2Dto->derivedFromVersionId)->toBe($v1Id);
    expect($v2Dto->redactionReason)->toBe('Patient identity anonymization for research');

    $refreshedDoc = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getDocument($docDto->id));
    expect($refreshedDoc?->currentVersionId)->toBe($v2Dto->id);
    expect($refreshedDoc?->versions)->toHaveCount(2);
});
