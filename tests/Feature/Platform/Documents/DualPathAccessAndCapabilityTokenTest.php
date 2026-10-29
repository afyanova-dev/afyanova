<?php

declare(strict_types=1);

use App\Platform\Documents\Application\Query\GetDocumentQuery;
use App\Platform\Documents\Application\Query\GetDownloadStreamQuery;
use App\Platform\Documents\Contracts\DocumentsApi;
use App\Platform\Documents\Contracts\DTO\GrantAccessDto;
use App\Platform\Documents\Contracts\DTO\UploadDocumentDto;
use App\Platform\Documents\Domain\Enum\ConfidentialityLevel;
use App\Platform\Documents\Domain\Enum\DocumentType;
use App\Platform\Documents\Domain\Enum\TokenAction;
use App\Platform\Documents\Domain\Exception\AmbiguousCredentialsException;
use App\Platform\Documents\Domain\Exception\DocumentNotAvailableException;
use App\Platform\Documents\Domain\Exception\InvalidCapabilityTokenException;
use App\Platform\Documents\Domain\Exception\TokenAlreadyConsumedException;
use App\Platform\Documents\Domain\ValueObject\DocumentCapabilityToken;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/documents.php';

beforeEach(function (): void {
    Storage::fake('local');
});

it('enforces Path A restricted confidentiality gate: staff requires explicit domain grant', function (): void {
    $fx = documentsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $api = app(DocumentsApi::class);
    $queryBus = app(QueryBus::class);

    $dto = new UploadDocumentDto(
        tenantId: $tenantId,
        facilityId: $facilityId,
        docType: DocumentType::CONSENT,
        confidentialityLevel: ConfidentialityLevel::RESTRICTED,
        sourceDomain: 'Clinical',
        sourceEntityRef: 'CONS-001',
        fileContents: 'Restricted psychiatric evaluation notes',
        mimeType: 'text/plain',
        fileName: 'notes.txt',
        actorId: $userId,
    );

    $docDto = TenantContextScope::run($tenantId, $facilityId, fn () => $api->upload($dto));

    $unauthorizedStaffId = (string) Str::uuid();

    // Querying restricted document without grant throws DocumentNotAvailableException
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => $queryBus->dispatch(new GetDocumentQuery(
        tenantId: $tenantId,
        documentId: $docDto->id,
        actorId: $unauthorizedStaffId,
    ))))->toThrow(DocumentNotAvailableException::class);

    // Grant access to staff
    TenantContextScope::run($tenantId, $facilityId, fn () => $api->grantAccess(new GrantAccessDto(
        tenantId: $tenantId,
        documentId: $docDto->id,
        principalId: $unauthorizedStaffId,
        scope: 'VIEW',
        expiresAt: CarbonImmutable::now()->addHours(2),
        grantedBy: $userId,
    )));

    // Now query succeeds
    $accessibleDoc = TenantContextScope::run($tenantId, $facilityId, fn () => $queryBus->dispatch(new GetDocumentQuery(
        tenantId: $tenantId,
        documentId: $docDto->id,
        actorId: $unauthorizedStaffId,
    )));

    expect($accessibleDoc)->not->toBeNull();
    expect($accessibleDoc->id)->toBe($docDto->id);
});

it('strictly rejects requests presenting dual credentials (actor identity and capability token)', function (): void {
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
        sourceEntityRef: 'INV-101',
        fileContents: 'Invoice bytes',
        mimeType: 'text/plain',
        fileName: 'inv.txt',
        actorId: $userId,
    )));

    $capabilityToken = new DocumentCapabilityToken(
        jti: (string) Str::uuid(),
        tenantId: $tenantId,
        documentId: $docDto->id,
        versionNo: 1,
        action: TokenAction::DOWNLOAD,
        expiresAt: CarbonImmutable::now()->addMinutes(10),
    );
    $signedToken = $capabilityToken->sign((string) config('app.key'));

    // Dual credentials in GetDocumentQuery
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => $queryBus->dispatch(new GetDocumentQuery(
        tenantId: $tenantId,
        documentId: $docDto->id,
        actorId: $userId,
        capabilityToken: $signedToken,
    ))))->toThrow(AmbiguousCredentialsException::class);

    // Dual credentials in GetDownloadStreamQuery
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => $queryBus->dispatch(new GetDownloadStreamQuery(
        tenantId: $tenantId,
        documentId: $docDto->id,
        versionNo: 1,
        actorId: $userId,
        capabilityToken: $signedToken,
    ))))->toThrow(AmbiguousCredentialsException::class);
});

it('enforces single-use replay protection on capability tokens', function (): void {
    $fx = documentsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $api = app(DocumentsApi::class);
    $queryBus = app(QueryBus::class);

    $docDto = TenantContextScope::run($tenantId, $facilityId, fn () => $api->upload(new UploadDocumentDto(
        tenantId: $tenantId,
        facilityId: $facilityId,
        docType: DocumentType::RECEIPT,
        confidentialityLevel: ConfidentialityLevel::STANDARD,
        sourceDomain: 'Billing',
        sourceEntityRef: 'REC-202',
        fileContents: 'Receipt #202 content',
        mimeType: 'text/plain',
        fileName: 'rec.txt',
        actorId: $userId,
    )));

    $jti = (string) Str::uuid();
    $capabilityToken = new DocumentCapabilityToken(
        jti: $jti,
        tenantId: $tenantId,
        documentId: $docDto->id,
        versionNo: 1,
        action: TokenAction::DOWNLOAD,
        expiresAt: CarbonImmutable::now()->addMinutes(10),
    );
    $signedToken = $capabilityToken->sign((string) config('app.key'));

    // First download succeeds and consumes token
    $stream1 = TenantContextScope::run($tenantId, $facilityId, fn () => $queryBus->dispatch(new GetDownloadStreamQuery(
        tenantId: $tenantId,
        documentId: $docDto->id,
        capabilityToken: $signedToken,
    )));

    expect($stream1->contents)->toBe('Receipt #202 content');

    // Second download using same token throws TokenAlreadyConsumedException
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => $queryBus->dispatch(new GetDownloadStreamQuery(
        tenantId: $tenantId,
        documentId: $docDto->id,
        capabilityToken: $signedToken,
    ))))->toThrow(TokenAlreadyConsumedException::class);
});

it('rejects expired or forged capability tokens', function (): void {
    $fx = documentsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $queryBus = app(QueryBus::class);

    // Expired token
    $expiredToken = new DocumentCapabilityToken(
        jti: (string) Str::uuid(),
        tenantId: $tenantId,
        documentId: (string) Str::uuid(),
        versionNo: 1,
        action: TokenAction::DOWNLOAD,
        expiresAt: CarbonImmutable::now()->subMinute(),
    );
    $signedExpired = $expiredToken->sign((string) config('app.key'));

    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => $queryBus->dispatch(new GetDownloadStreamQuery(
        tenantId: $tenantId,
        documentId: $expiredToken->documentId,
        capabilityToken: $signedExpired,
    ))))->toThrow(InvalidCapabilityTokenException::class);

    // Forged signature
    $tamperedToken = $signedExpired.'badsignature';
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => $queryBus->dispatch(new GetDownloadStreamQuery(
        tenantId: $tenantId,
        documentId: $expiredToken->documentId,
        capabilityToken: $tamperedToken,
    ))))->toThrow(InvalidCapabilityTokenException::class);
});
