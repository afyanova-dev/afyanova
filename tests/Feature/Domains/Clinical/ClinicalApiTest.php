<?php

declare(strict_types=1);

use App\Domains\Clinical\Contracts\ClinicalApi;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/clinical.php';

test("a clinician cannot read another user's documentation draft", function (): void {
    $fixture = clinicalFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $otherUser = User::factory()->for($fixture['tenant'])->create();

    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => app(ClinicalApi::class)
        ->getDocumentationDraft(
            $tenantId,
            $facilityId,
            (string) $otherUser->getAuthIdentifier(),
            (string) str()->uuid(),
            'NOTE',
        )))->toThrow(RuntimeException::class);
});

test('a documentation draft requires the active tenant and facility context', function (): void {
    $fixture = clinicalFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $userId = (string) $fixture['user']->getAuthIdentifier();

    foreach ([
        ['tenantId' => (string) str()->uuid(), 'facilityId' => $facilityId],
        ['tenantId' => $tenantId, 'facilityId' => (string) str()->uuid()],
    ] as $requestedContext) {
        expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => app(ClinicalApi::class)
            ->getDocumentationDraft(
                $requestedContext['tenantId'],
                $requestedContext['facilityId'],
                $userId,
                (string) str()->uuid(),
                'NOTE',
            )))->toThrow(RuntimeException::class);
    }
});

test('a documentation draft requires permission for its workflow section', function (): void {
    $fixture = clinicalFixture([
        'clinical.access',
        'encounter.encounters.read',
        'patient.patients.read',
    ]);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $userId = (string) $fixture['user']->getAuthIdentifier();

    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => app(ClinicalApi::class)
        ->getDocumentationDraft(
            $tenantId,
            $facilityId,
            $userId,
            (string) str()->uuid(),
            'NOTE',
        )))->toThrow(RuntimeException::class);
});
