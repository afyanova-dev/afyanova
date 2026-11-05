<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Platform\Documents\Contracts\DocumentsApi;
use App\Platform\Documents\Contracts\DTO\DocumentDto;
use App\Platform\Documents\Contracts\DTO\GrantAccessDto;
use App\Platform\Documents\Contracts\DTO\ImposeRetentionDto;
use App\Platform\Documents\Contracts\DTO\UploadDocumentDto;
use App\Platform\Documents\Contracts\Enum\ConfidentialityLevel;
use App\Platform\Documents\Contracts\Enum\DocumentType;
use App\Platform\Documents\Domain\Enum\RetentionImposedBy;
use App\Platform\Documents\Domain\Exception\DocumentNotAvailableException;
use App\Platform\Documents\Domain\Exception\DocumentNotFoundException;
use App\Platform\Documents\Domain\Exception\TenantIsolationViolationException;
use App\Platform\Documents\Domain\Model\Document;
use App\Platform\Documents\Domain\Model\DocumentVersion;
use App\Platform\Documents\Domain\ValueObject\StorageKey;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/documents.php';

beforeEach(function (): void {
    Storage::fake('local');
});

it('strictly isolates documents across tenants via PostgreSQL RLS and tenant scoping', function (): void {
    $fxA = documentsFixture();
    $tenantA = (string) $fxA['tenant']->id;
    $facilityA = (string) $fxA['facility']->id;
    $userA = (string) $fxA['user']->id;

    $fxB = documentsFixture();
    $tenantB = (string) $fxB['tenant']->id;
    $facilityB = (string) $fxB['facility']->id;
    $userB = (string) $fxB['user']->id;

    $api = app(DocumentsApi::class);

    // Tenant A uploads a document
    $docA = TenantContextScope::run($tenantA, $facilityA, fn () => $api->upload(new UploadDocumentDto(
        tenantId: $tenantA,
        facilityId: $facilityA,
        docType: DocumentType::LAB_REPORT,
        confidentialityLevel: ConfidentialityLevel::STANDARD,
        sourceDomain: 'Laboratory',
        sourceEntityRef: 'LAB-T-A',
        fileContents: 'Confidential lab report for Tenant A',
        mimeType: 'text/plain',
        fileName: 'labA.txt',
        actorId: $userA,
    )));

    // Tenant B queries Tenant A's document via Eloquent -> returns null (scoped out)
    $queriedByB = TenantContextScope::run($tenantB, $facilityB, fn () => Document::query()->where('id', $docA->id)->first());
    expect($queriedByB)->toBeNull();

    // Tenant B queries versions for Tenant A's document -> returns empty
    $versionsQueriedByB = TenantContextScope::run($tenantB, $facilityB, fn () => DocumentVersion::query()->where('document_id', $docA->id)->get());
    expect($versionsQueriedByB)->toBeEmpty();
});

it('limits document reads to the active facility and authorized facilities in general context', function (): void {
    $fx = documentsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityA = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $facilityB = TenantContextScope::run($tenantId, null, fn (): Facility => Facility::factory()
        ->forTenant($tenantId, (string) $fx['organization']->id)
        ->create());

    $api = app(DocumentsApi::class);
    $document = TenantContextScope::run($tenantId, $facilityA, fn () => $api->upload(new UploadDocumentDto(
        tenantId: $tenantId,
        facilityId: $facilityA,
        docType: DocumentType::LAB_REPORT,
        confidentialityLevel: ConfidentialityLevel::STANDARD,
        sourceDomain: 'Laboratory',
        sourceEntityRef: 'LAB-FACILITY-A',
        fileContents: 'Facility A lab report',
        mimeType: 'text/plain',
        fileName: 'lab.txt',
        actorId: $userId,
    )));

    $userInFacilityB = makeUserWithPermissionsInTenant(
        $fx['tenant'],
        $facilityB,
        [
            'documents.access',
            'documents.document.upload',
            'documents.document.view',
        ],
    );
    $facilityBDocument = TenantContextScope::run($tenantId, (string) $facilityB->id, function () use ($api, $tenantId, $facilityB, $userInFacilityB): DocumentDto {
        test()->actingAs($userInFacilityB);

        return $api->upload(new UploadDocumentDto(
            tenantId: $tenantId,
            facilityId: (string) $facilityB->id,
            docType: DocumentType::LAB_REPORT,
            confidentialityLevel: ConfidentialityLevel::STANDARD,
            sourceDomain: 'Laboratory',
            sourceEntityRef: 'LAB-FACILITY-B',
            fileContents: 'Facility B lab report',
            mimeType: 'text/plain',
            fileName: 'lab-b.txt',
            actorId: (string) $userInFacilityB->id,
        ));
    });
    $this->actingAs($fx['user']);

    $documentInFacilityB = TenantContextScope::run(
        $tenantId,
        (string) $facilityB->id,
        fn () => $api->getDocument($document->id),
    );
    $documentInGeneralContext = TenantContextScope::run($tenantId, null, fn () => $api->getDocument($document->id));
    $unauthorizedDocumentInGeneralContext = TenantContextScope::run($tenantId, null, fn () => $api->getDocument($facilityBDocument->id));

    expect($documentInFacilityB)->toBeNull();
    expect($documentInGeneralContext?->id)->toBe($document->id);
    expect($unauthorizedDocumentInGeneralContext)->toBeNull();
});

it('does not download a document from another facility', function (): void {
    $fx = documentsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityA = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $facilityB = TenantContextScope::run($tenantId, null, fn (): Facility => Facility::factory()
        ->forTenant($tenantId, (string) $fx['organization']->id)
        ->create());

    $api = app(DocumentsApi::class);
    $document = TenantContextScope::run($tenantId, $facilityA, fn () => $api->upload(new UploadDocumentDto(
        tenantId: $tenantId,
        facilityId: $facilityA,
        docType: DocumentType::LAB_REPORT,
        confidentialityLevel: ConfidentialityLevel::STANDARD,
        sourceDomain: 'Laboratory',
        sourceEntityRef: 'LAB-FACILITY-A',
        fileContents: 'Facility A lab report',
        mimeType: 'text/plain',
        fileName: 'lab.txt',
        actorId: $userId,
    )));

    expect(fn () => TenantContextScope::run(
        $tenantId,
        (string) $facilityB->id,
        fn () => $api->getDownloadStream($tenantId, $document->id, $userId),
    ))->toThrow(DocumentNotFoundException::class);
});

it('rejects document uploads targeting a different facility than the active context', function (): void {
    $fx = documentsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityA = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $facilityB = TenantContextScope::run($tenantId, null, fn (): Facility => Facility::factory()
        ->forTenant($tenantId, (string) $fx['organization']->id)
        ->create());

    $api = app(DocumentsApi::class);

    expect(fn () => TenantContextScope::run($tenantId, (string) $facilityB->id, fn () => $api->upload(new UploadDocumentDto(
        tenantId: $tenantId,
        facilityId: $facilityA,
        docType: DocumentType::LAB_REPORT,
        confidentialityLevel: ConfidentialityLevel::STANDARD,
        sourceDomain: 'Laboratory',
        sourceEntityRef: 'LAB-FACILITY-A',
        fileContents: 'Facility A lab report',
        mimeType: 'text/plain',
        fileName: 'lab.txt',
        actorId: $userId,
    ))))->toThrow(DocumentNotAvailableException::class);

    expect(fn () => TenantContextScope::run($tenantId, null, fn () => $api->upload(new UploadDocumentDto(
        tenantId: $tenantId,
        facilityId: $facilityA,
        docType: DocumentType::LAB_REPORT,
        confidentialityLevel: ConfidentialityLevel::STANDARD,
        sourceDomain: 'Laboratory',
        sourceEntityRef: 'LAB-GENERAL-CONTEXT',
        fileContents: 'Facility A lab report',
        mimeType: 'text/plain',
        fileName: 'lab.txt',
        actorId: $userId,
    ))))->toThrow(DocumentNotAvailableException::class);
});

it('does not revoke facility-owned grants or retention from another facility', function (): void {
    $fx = documentsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityA = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $facilityB = TenantContextScope::run($tenantId, null, fn (): Facility => Facility::factory()
        ->forTenant($tenantId, (string) $fx['organization']->id)
        ->create());

    $api = app(DocumentsApi::class);
    $document = TenantContextScope::run($tenantId, $facilityA, fn () => $api->upload(new UploadDocumentDto(
        tenantId: $tenantId,
        facilityId: $facilityA,
        docType: DocumentType::LAB_REPORT,
        confidentialityLevel: ConfidentialityLevel::STANDARD,
        sourceDomain: 'Laboratory',
        sourceEntityRef: 'LAB-FACILITY-A',
        fileContents: 'Facility A lab report',
        mimeType: 'text/plain',
        fileName: 'lab.txt',
        actorId: $userId,
    )));

    TenantContextScope::run($tenantId, $facilityA, function () use ($api, $tenantId, $document, $userId): void {
        $api->grantAccess(new GrantAccessDto(
            tenantId: $tenantId,
            documentId: $document->id,
            principalId: $userId,
            scope: 'VIEW',
            expiresAt: CarbonImmutable::now()->addHour(),
            grantedBy: $userId,
        ));
        $api->imposeRetention(new ImposeRetentionDto(
            tenantId: $tenantId,
            documentId: $document->id,
            imposedBy: RetentionImposedBy::GOVERNANCE,
            reason: 'Facility A statutory retention',
            actorId: $userId,
            retainUntil: CarbonImmutable::now()->addYear(),
        ));
    });

    $grantId = TenantContextScope::run($tenantId, $facilityA, fn (): string => (string) DB::table('doc_access_grants')
        ->where('document_id', $document->id)
        ->value('id'));
    $requirementId = TenantContextScope::run($tenantId, $facilityA, fn (): string => (string) DB::table('doc_retention_requirements')
        ->where('document_id', $document->id)
        ->value('id'));

    expect($grantId)->not->toBe('');
    expect($requirementId)->not->toBe('');

    TenantContextScope::run($tenantId, (string) $facilityB->id, fn () => $api->revokeAccess($grantId));

    expect(fn () => TenantContextScope::run(
        $tenantId,
        (string) $facilityB->id,
        fn () => $api->releaseRetention($requirementId),
    ))->toThrow(DocumentNotFoundException::class);

    $recordsRemainIntact = TenantContextScope::run($tenantId, $facilityA, fn (): array => [
        'grant_exists' => DB::table('doc_access_grants')->where('id', $grantId)->exists(),
        'released_at' => DB::table('doc_retention_requirements')->where('id', $requirementId)->value('released_at'),
    ]);

    expect($recordsRemainIntact)->toBe([
        'grant_exists' => true,
        'released_at' => null,
    ]);
});

it('enforces strict tenant path validation in StorageKey', function (): void {
    $tenantA = (string) Str::uuid();
    $tenantB = (string) Str::uuid();
    $docId = (string) Str::uuid();
    $hash = hash('sha256', 'test');

    $storageKeyA = StorageKey::forVersion($tenantA, $docId, 1, $hash, 'pdf');

    // Asserts true for owner tenant
    $storageKeyA->assertBelongsToTenant($tenantA);

    // Throws TenantIsolationViolationException if accessed by different tenant
    expect(fn () => $storageKeyA->assertBelongsToTenant($tenantB))
        ->toThrow(TenantIsolationViolationException::class);
});
