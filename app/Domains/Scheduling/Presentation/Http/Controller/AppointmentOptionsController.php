<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Presentation\Http\Controller;

use App\Domains\Scheduling\Application\Query\GetAppointmentTypes;
use App\Domains\Scheduling\Application\Query\GetSchedulableResources;
use App\Models\User;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AppointmentOptionsController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        QueryBus $queryBus,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('sch.access'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        /** @var list<object> $types */
        $types = $queryBus->dispatch(new GetAppointmentTypes(facilityId: $facilityId));
        /** @var list<object> $resources */
        $resources = $queryBus->dispatch(new GetSchedulableResources(facilityId: $facilityId));

        return response()->json([
            'types' => array_map(static fn ($t): array => [
                'id' => $t->id,
                'code' => $t->code,
                'name' => $t->name,
                'durationMinutes' => $t->durationMinutes,
            ], $types),
            'resources' => array_map(static fn ($r): array => [
                'id' => $r->id,
                'name' => $r->name,
                'resourceType' => $r->resourceType,
            ], $resources),
        ]);
    }
}
