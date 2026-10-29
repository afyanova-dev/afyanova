<?php

declare(strict_types=1);

use App\Platform\Documents\Application\Query\GetDownloadStreamQuery;
use App\Platform\Documents\Contracts\DocumentsApi;
use App\Platform\Documents\Contracts\DTO\ImposeRetentionDto;
use App\Platform\Documents\Contracts\DTO\UploadDocumentDto;
use App\Platform\Documents\Domain\Enum\ConfidentialityLevel;
use App\Platform\Documents\Domain\Enum\DocumentStatus;
use App\Platform\Documents\Domain\Enum\DocumentType;
use App\Platform\Documents\Domain\Enum\RetentionImposedBy;
use App\Platform\Documents\Domain\Exception\DisposalBlockedException;
use App\Platform\Documents\Domain\Exception\DocumentVersionDisposedException;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../../../tests/Support/documents.php';

beforeEach(function (): void {
    Storage::fake('local');
});

it('blocks disposal while retention requirements or legal holds are active', function (): void {
    $fx = documentsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $api = app(DocumentsApi::class);

    $docDto = TenantContextScope::run($tenantId, $facilityId, fn () => $api->upload(new UploadDocumentDto(
        tenantId: $tenantId,
        facilityId: $facilityId,
        docType: DocumentType::LAB_REPORT,
        confidentialityLevel: ConfidentialityLevel::STANDARD,
        sourceDomain: 'Laboratory',
        sourceEntityRef: 'LAB-ORD-555',
        fileContents: 'Blood culture test results',
        mimeType: 'text/plain',
        fileName: 'lab.txt',
        actorId: $userId,
    )));

    $v1 = $docDto->versions[0];

    // Impose retention requirement (retained for 7 years)
    TenantContextScope::run($tenantId, $facilityId, fn () => $api->imposeRetention(new ImposeRetentionDto(
        tenantId: $tenantId,
        documentId: $docDto->id,
        imposedBy: RetentionImposedBy::GOVERNANCE,
        reason: 'Statutory 7-year medical records retention',
        actorId: $userId,
        retainUntil: CarbonImmutable::now()->addYears(7),
    )));

    $retentionStatus = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getRetentionStatus($docDto->id));
    expect($retentionStatus->isRetained)->toBeTrue();
    expect($retentionStatus->canBeDisposed)->toBeFalse();

    // Attempt disposal — blocked
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => $api->disposeVersion(
        versionId: $v1->id,
        reason: 'Premature disposal attempt',
    )))->toThrow(DisposalBlockedException::class);
});

it('executes three-phase defensible disposal with physical shredding and anti-reversion', function (): void {
    $fx = documentsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $api = app(DocumentsApi::class);
    $queryBus = app(QueryBus::class);

    $docDto = TenantContextScope::run($tenantId, $facilityId, fn () => $api->upload(new UploadDocumentDto(
        tenantId: $tenantId,
        facilityId: $facilityId,
        docType: DocumentType::INVOICE,
        confidentialityLevel: ConfidentialityLevel::STANDARD,
        sourceDomain: 'Billing',
        sourceEntityRef: 'INV-TEMP',
        fileContents: 'Temporary invoice bytes to be disposed',
        mimeType: 'text/plain',
        fileName: 'temp.txt',
        actorId: $userId,
    )));

    $v1 = $docDto->versions[0];
    $storageKey = (string) $v1->storageKey;

    // Verify file exists in storage
    expect(Storage::disk('local')->exists($storageKey))->toBeTrue();

    // Dispose version
    TenantContextScope::run($tenantId, $facilityId, fn () => $api->disposeVersion(
        versionId: $v1->id,
        reason: 'Temporary document cleanup',
    ));

    // 1. Physical bytes shredded and deleted from disk
    expect(Storage::disk('local')->exists($storageKey))->toBeFalse();

    // 2. Version record preserved as tombstone in database with status DISPOSED
    $versionRow = DB::table('doc_document_versions')->where('id', $v1->id)->first();
    expect($versionRow->status)->toBe(DocumentStatus::DISPOSED->value);
    expect($versionRow->storage_key)->toBeNull();
    expect($versionRow->disposed_at)->not->toBeNull();

    // 3. Anti-reversion check: download stream throws DocumentVersionDisposedException
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => $queryBus->dispatch(new GetDownloadStreamQuery(
        tenantId: $tenantId,
        documentId: $docDto->id,
        versionNo: 1,
        actorId: $userId,
    ))))->toThrow(DocumentVersionDisposedException::class);
});
