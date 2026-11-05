<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Presentation\Http\Controller;

use App\Domains\Scheduling\Application\Query\GetAppointmentTypes;
use App\Domains\Scheduling\Application\Query\GetSchedulableResources;
use App\Domains\Scheduling\Contracts\Dto\AppointmentTypeSummary;
use App\Domains\Scheduling\Contracts\Dto\SchedulableResourceSummary;
use App\Models\User;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use UnexpectedValueException;

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

        $typeResults = $queryBus->dispatch(new GetAppointmentTypes(facilityId: $facilityId));
        if (! is_array($typeResults) || ! array_is_list($typeResults)) {
            throw new UnexpectedValueException('The appointment type query must return a list.');
        }

        $types = [];
        foreach ($typeResults as $type) {
            if (! $type instanceof AppointmentTypeSummary) {
                throw new UnexpectedValueException('The appointment type query returned an invalid result.');
            }

            $types[] = $type;
        }

        $resourceResults = $queryBus->dispatch(new GetSchedulableResources(facilityId: $facilityId));
        if (! is_array($resourceResults) || ! array_is_list($resourceResults)) {
            throw new UnexpectedValueException('The schedulable resource query must return a list.');
        }

        $resources = [];
        foreach ($resourceResults as $resource) {
            if (! $resource instanceof SchedulableResourceSummary) {
                throw new UnexpectedValueException('The schedulable resource query returned an invalid result.');
            }

            $resources[] = $resource;
        }

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
