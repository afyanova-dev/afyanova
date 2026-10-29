<?php

declare(strict_types=1);

use App\Platform\Documents\Contracts\DocumentsApi;
use App\Platform\Documents\Contracts\DTO\UploadDocumentDto;
use App\Platform\Documents\Domain\Enum\ConfidentialityLevel;
use App\Platform\Documents\Domain\Enum\DocumentType;
use App\Platform\Documents\Domain\Exception\TenantIsolationViolationException;
use App\Platform\Documents\Domain\Model\Document;
use App\Platform\Documents\Domain\Model\DocumentVersion;
use App\Platform\Documents\Domain\ValueObject\StorageKey;
use App\Shared\Tenancy\TenantContextScope;
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
