<?php

declare(strict_types=1);

use App\Platform\Documents\Contracts\DocumentsApi;
use App\Platform\Documents\Contracts\DTO\AttachSignatureDto;
use App\Platform\Documents\Contracts\DTO\UploadDocumentDto;
use App\Platform\Documents\Domain\Enum\ConfidentialityLevel;
use App\Platform\Documents\Domain\Enum\DocumentType;
use App\Platform\Documents\Domain\Enum\SignatureMethod;
use App\Platform\Documents\Domain\Enum\SignatureVerificationStatus;
use App\Platform\Documents\Domain\Enum\SignerType;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/documents.php';

beforeEach(function (): void {
    Storage::fake('local');
});

it('binds authoritative signature metadata and preserves post-disposal tombstones', function (): void {
    $fx = documentsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $api = app(DocumentsApi::class);

    $content = 'Surgical operation consent form signed by patient.';
    $contentHash = hash('sha256', $content);

    $docDto = TenantContextScope::run($tenantId, $facilityId, fn () => $api->upload(new UploadDocumentDto(
        tenantId: $tenantId,
        facilityId: $facilityId,
        docType: DocumentType::CONSENT,
        confidentialityLevel: ConfidentialityLevel::STANDARD,
        sourceDomain: 'Theatre',
        sourceEntityRef: 'SURG-001',
        fileContents: $content,
        mimeType: 'text/plain',
        fileName: 'consent.txt',
        actorId: $userId,
    )));

    $v1 = $docDto->versions[0];
    $signerId = (string) Str::uuid();
    $authEventRef = (string) Str::uuid();

    // Attach signature
    $sigDigest = hash('sha256', $contentHash.':'.$signerId);
    $signedVersion = TenantContextScope::run($tenantId, $facilityId, fn () => $api->attachSignature(new AttachSignatureDto(
        tenantId: $tenantId,
        documentVersionId: $v1->id,
        signerId: $signerId,
        signerType: SignerType::PATIENT,
        method: SignatureMethod::CRYPTO_SEAL,
        signatureDigest: $sigDigest,
        signedAt: CarbonImmutable::now(),
        authEventRef: $authEventRef,
        certFingerprint: 'SHA256:FINGERPRINT1234567890',
    )));

    expect($signedVersion->id)->toBe($v1->id);

    // Retrieve signature from database
    $signatureRow = DB::table('doc_signatures')->where('document_version_id', $v1->id)->first();
    expect($signatureRow)->not->toBeNull();
    expect($signatureRow->signature_digest)->toBe($sigDigest);
    expect($signatureRow->auth_event_ref)->toBe($authEventRef);

    // Verify signature before disposal -> VALID
    $verifyReport = TenantContextScope::run($tenantId, $facilityId, fn () => $api->verifySignature((string) $signatureRow->id));
    expect($verifyReport->status)->toBe(SignatureVerificationStatus::VALID);
    expect($verifyReport->contentHash)->toBe($contentHash);

    // Now dispose version physically
    TenantContextScope::run($tenantId, $facilityId, fn () => $api->disposeVersion($v1->id, 'Statutory disposal after retention period'));

    // Verify signature post-disposal -> VERSION_DISPOSED (tombstone preserves signature attestation)
    $verifyReportPostDisposal = TenantContextScope::run($tenantId, $facilityId, fn () => $api->verifySignature((string) $signatureRow->id));
    expect($verifyReportPostDisposal->status)->toBe(SignatureVerificationStatus::VERSION_DISPOSED);
    expect($verifyReportPostDisposal->contentHash)->toBeNull();
    expect($verifyReportPostDisposal->signatureDigest)->toBe($sigDigest);
});
