<?php

declare(strict_types=1);

namespace App\Domains\Patient\Presentation\Http\Controller;

use App\Domains\Patient\Application\Query\GetRecentPatients;
use App\Domains\Patient\Application\Query\SearchPatients;
use App\Domains\Patient\Contracts\Dto\PatientSearchResult;
use App\Domains\Patient\Domain\Exception\SearchCriteriaTooBroad;
use App\Domains\Patient\Presentation\Http\PatientSearchQueryResolver;
use App\Models\User;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PatientSearchBrowseController
{
    public function __invoke(Request $request, TenantContext $context, QueryBus $queryBus): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('patient.patients.read'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $q = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(SearchPatients::MAX_PER_PAGE, (int) $request->query('per_page', 15)));
        $searchError = null;

        try {
            $searchQuery = PatientSearchQueryResolver::resolve($request, $page, $perPage);
            if ($searchQuery === null) {
                /** @var LengthAwarePaginator<int, PatientSearchResult> $results */
                $results = $queryBus->dispatch(new GetRecentPatients(
                    facilityId: $facilityId,
                    page: $page,
                    perPage: $perPage,
                ));
                $mode = 'browse';
            } else {
                /** @var LengthAwarePaginator<int, PatientSearchResult> $results */
                $results = $queryBus->dispatch($searchQuery);
                $mode = 'search';
            }
        } catch (SearchCriteriaTooBroad $e) {
            $results = null;
            $searchError = $e->getMessage();
            $mode = 'search';
        }

        return Inertia::render('Patients/Index', [
            'q' => $q,
            'mode' => $mode,
            'patients' => $results?->items() ?? [],
            'total' => $results?->total() ?? 0,
            'currentPage' => $results?->currentPage() ?? $page,
            'lastPage' => $results?->lastPage() ?? 1,
            'perPage' => $perPage,
            'searchError' => $searchError,
        ]);
    }
}
