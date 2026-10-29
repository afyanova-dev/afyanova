<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Presentation\Http\Controller;

use App\Domains\Scheduling\Application\Query\GetFacilityAppointmentCalendarQuery;
use App\Models\User;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AppointmentCalendarDataController
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

        try {
            $start = CarbonImmutable::parse((string) $request->input('start', ''))->startOfDay();
            $end = CarbonImmutable::parse((string) $request->input('end', ''))->startOfDay();
        } catch (\Throwable) {
            return response()->json(['days' => []]);
        }

        if ($end->lessThan($start) || $start->diffInDays($end) > 62) {
            return response()->json(['days' => []]);
        }

        try {
            /** @var list<array{date: string, total: int, services: list<array{type: string, count: int}>}> $days */
            $days = $queryBus->dispatch(new GetFacilityAppointmentCalendarQuery($facilityId, $start, $end));
        } catch (\Throwable) {
            return response()->json(['days' => []]);
        }

        return response()->json(['days' => $days]);
    }
}
