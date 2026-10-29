<?php

declare(strict_types=1);

use App\Platform\Documents\Contracts\DocumentsApi;
use App\Platform\Documents\Contracts\DTO\GenerateDocumentDto;
use App\Platform\Documents\Domain\Enum\ConfidentialityLevel;
use App\Platform\Documents\Domain\Enum\DocumentKind;
use App\Platform\Documents\Domain\Enum\DocumentType;
use App\Platform\Documents\Domain\Model\DocumentTemplate;
use App\Platform\Documents\Domain\Repository\DocumentTemplateRepositoryInterface;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../../../tests/Support/documents.php';

beforeEach(function (): void {
    Storage::fake('local');
});

it('demonstrates push-based deterministic generation and read-only reproducibility check', function (): void {
    $fx = documentsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $api = app(DocumentsApi::class);
    $templateRepo = app(DocumentTemplateRepositoryInterface::class);

    // Register a Blade template
    $templateId = 'invoice.standard';
    $templateVersion = '1.0';
    $layout = '<h1>Invoice {{ $invoice_no }}</h1><p>Patient: {{ $patient_name }}</p><p>Total: {{ $total }}</p>';

    TenantContextScope::run($tenantId, $facilityId, function () use ($templateRepo, $tenantId, $templateId, $templateVersion, $layout): void {
        $template = DocumentTemplate::createTemplate(
            tenantId: $tenantId,
            id: $templateId,
            docType: DocumentType::INVOICE,
            name: 'Standard Invoice Template',
            layout: $layout,
            version: $templateVersion,
        );
        $templateRepo->save($template);
    });

    // Generate document
    $payload = [
        'invoice_no' => 'INV-2026-0001',
        'patient_name' => 'Fatuma Ali',
        'total' => '15000.00',
    ];

    $docDto = TenantContextScope::run($tenantId, $facilityId, fn () => $api->generate(new GenerateDocumentDto(
        tenantId: $tenantId,
        facilityId: $facilityId,
        docType: DocumentType::INVOICE,
        confidentialityLevel: ConfidentialityLevel::STANDARD,
        sourceDomain: 'Billing',
        sourceEntityRef: 'INV-2026-0001',
        templateId: $templateId,
        templateVersion: $templateVersion,
        payload: $payload,
        actorId: $userId,
    )));

    expect($docDto->kind)->toBe(DocumentKind::GENERATED);
    expect($docDto->versions)->toHaveCount(1);

    $v1 = $docDto->versions[0];
    expect($v1->generationContextHash)->not->toBeNull();
    expect($v1->templateId)->toBe($templateId);
    expect($v1->templateVersion)->toBe($templateVersion);

    // Verify reproducibility
    $report = TenantContextScope::run($tenantId, $facilityId, fn () => $api->verifyReproducibility($v1->id));

    expect($report->isReproducible)->toBeTrue();
    expect($report->storedHash)->toBe($v1->contentHash);
    expect($report->recomputedHash)->toBe($v1->contentHash);
    expect($report->generationContextHash)->toBe($v1->generationContextHash);
});
