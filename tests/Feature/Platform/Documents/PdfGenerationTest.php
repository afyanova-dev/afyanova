<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Documents;

use App\Platform\Documents\Contracts\DocumentsApi;
use App\Platform\Documents\Contracts\DTO\GenerateDocumentDto;
use App\Platform\Documents\Domain\Enum\ConfidentialityLevel;
use App\Platform\Documents\Domain\Enum\DocumentType;
use App\Platform\Documents\Domain\Model\DocumentTemplate;
use App\Platform\Documents\Domain\Port\DocumentStoragePort;
use App\Platform\Documents\Domain\Repository\DocumentTemplateRepositoryInterface;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../../../tests/Support/documents.php';

beforeEach(function (): void {
    Storage::fake('local');
});

test('GenerateDocument with format pdf creates valid binary PDF with application/pdf mime type', function (): void {
    $fx = documentsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $api = app(DocumentsApi::class);
    $templateRepo = app(DocumentTemplateRepositoryInterface::class);
    $storage = app(DocumentStoragePort::class);

    $templateId = 'discharge.summary';
    $templateVersion = '1.0';
    $layout = '<html><body><h1>Discharge Summary</h1><p>Patient: {{ $patient_name }}</p><p>Summary: {{ $summary }}</p></body></html>';

    TenantContextScope::run($tenantId, $facilityId, function () use ($templateRepo, $tenantId, $templateId, $templateVersion, $layout): void {
        $template = DocumentTemplate::createTemplate(
            tenantId: $tenantId,
            id: $templateId,
            docType: DocumentType::DISCHARGE_SUMMARY,
            name: 'Standard Discharge Summary',
            layout: $layout,
            version: $templateVersion,
        );
        $templateRepo->save($template);
    });

    $payload = [
        'patient_name' => 'Amina Hassan',
        'summary' => 'Patient treated for uncomplicated malaria, fully recovered and discharged home.',
    ];

    $docDto = TenantContextScope::run($tenantId, $facilityId, fn () => $api->generate(new GenerateDocumentDto(
        tenantId: $tenantId,
        facilityId: $facilityId,
        docType: DocumentType::DISCHARGE_SUMMARY,
        confidentialityLevel: ConfidentialityLevel::STANDARD,
        sourceDomain: 'Clinical',
        sourceEntityRef: 'ENC-2026-MAL-01',
        templateId: $templateId,
        templateVersion: $templateVersion,
        payload: $payload,
        actorId: $userId,
        format: 'pdf',
    )));

    expect($docDto)->not->toBeNull();
    expect($docDto->versions)->toHaveCount(1);

    $version = $docDto->versions[0];
    expect($version->mimeType)->toBe('application/pdf');
    expect($version->storageKey)->toEndWith('.pdf');
    expect($version->byteSize)->toBeGreaterThan(100);

    // Retrieve bytes from storage and verify PDF magic bytes (%PDF-)
    $pdfBytes = $storage->get($version->storageKey);
    expect($pdfBytes)->not->toBeNull();
    expect(str_starts_with($pdfBytes, '%PDF-'))->toBeTrue();
});
