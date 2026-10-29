<?php

declare(strict_types=1);

namespace App\Domains\Organization\Presentation\Http\Controller;

use App\Domains\Organization\Contracts\OrganizationApi;
use App\Models\User;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FacilityStructureController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        OrganizationApi $organizationApi,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('organization.structure.read'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $structure = $organizationApi->getFacilityStructure($facilityId);
        abort_if($structure === null, 404, 'Facility structure not found.');

        return response()->json([
            'facilityId' => $structure->facilityId,
            'facilityName' => $structure->facilityName,
            'departments' => array_map(static fn (array $department): array => [
                'id' => $department['id'],
                'name' => $department['name'],
                'code' => $department['code'],
                'units' => array_map(static fn (array $unit): array => [
                    'id' => $unit['id'],
                    'name' => $unit['name'],
                    'code' => $unit['code'],
                ], $department['units']),
            ], $structure->departments),
        ]);
    }
}
