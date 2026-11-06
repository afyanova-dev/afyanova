<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Controller;

use App\Domains\Clinical\Application\ClinicalDocumentationDraftService;
use App\Domains\Clinical\Presentation\Http\Request\SaveClinicalDocumentationDraftRequest;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Models\User;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Persists and clears the current author's short-lived workstation drafts.
 * Drafts are not clinical records; a successful note / diagnosis / plan command
 * clears the matching draft in the command controller.
 */
final class ClinicalDocumentationDraftController
{
    /** @var array<string, list<string>> */
    private const SECTION_FIELDS = [
        'NOTE' => ['note_type', 'title', 'body'],
        'DIAGNOSIS' => ['description', 'code', 'code_system', 'clinical_status'],
        'PLAN' => ['plan_type', 'clinical_intent', 'goals', 'planned_actions', 'instructions', 'follow_up'],
    ];

    public function save(
        SaveClinicalDocumentationDraftRequest $request,
        TenantContext $context,
        EncounterApi $encounters,
        ClinicalDocumentationDraftService $drafts,
        string $encounter,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $data = $request->validated();
        $encounterContext = $encounters->getEncounterContext($encounter);
        abort_unless(
            $encounterContext->facilityId === $facilityId
                && $encounterContext->patientId === $data['patient_id'],
            404,
        );
        abort_unless(
            in_array($encounterContext->status, ['ARRIVED', 'ACTIVE'], true),
            409,
            'Documentation drafts are only available for an open encounter.',
        );

        $section = (string) $data['section'];
        $values = array_intersect_key(
            $data['values'],
            array_fill_keys(self::SECTION_FIELDS[$section], true),
        );

        return response()->json($drafts->save(
            (string) $context->tenantId(),
            $facilityId,
            (string) $user->getAuthIdentifier(),
            $data['patient_id'],
            $encounter,
            $section,
            $values,
        ));
    }

    public function destroy(
        Request $request,
        TenantContext $context,
        ClinicalDocumentationDraftService $drafts,
        string $encounter,
        string $section,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $section = strtoupper($section);
        abort_unless(array_key_exists($section, self::SECTION_FIELDS), 404);
        $this->authorizeSection($user, $section);

        $drafts->delete(
            (string) $context->tenantId(),
            $facilityId,
            (string) $user->getAuthIdentifier(),
            $encounter,
            $section,
        );

        return response()->noContent();
    }

    private function authorizeSection(User $user, string $section): void
    {
        $allowed = match ($section) {
            'NOTE' => $user->can('clinical.notes.create') && $user->can('clinical.consultations.create'),
            'DIAGNOSIS' => $user->can('clinical.diagnoses.record'),
            'PLAN' => $user->can('clinical.plans.record'),
            default => false,
        };

        abort_unless($allowed, 403);
    }
}
