<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Query;

use App\Domains\Patient\Contracts\Dto\PatientSearchResult;
use App\Domains\Patient\Domain\PatientSearchReader;
use App\Models\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `SearchPatients` (IC §6.3): resolve the acting user, run the
 * bounded facility reader, then conditionally write the anti-enumeration audit
 * row for name-fragment searches (ADR-129 G). No transaction — the QueryBus
 * runs inside the request tenant scope (IC §6.1).
 *
 * The acting user is taken from `Auth`, NOT from the query DTO: ADR-129 G's
 * audit is a security control, so the identity it keys on must be the
 * authenticated principal the QueryBus policy already authorized — not a
 * client-supplied field a caller could spoof to dodge the audit.
 */
final class SearchPatientsHandler
{
    public function __construct(
        private readonly PatientSearchReader $reader,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return LengthAwarePaginator<int, PatientSearchResult>
     */
    public function __invoke(SearchPatients $query): LengthAwarePaginator
    {
        $userId = $this->actingUserId();

        $facilityId = $this->context->requireFacilityId();
        $results = $this->reader->search(
            $query->criteria,
            $userId,
            $facilityId,
            $query->page,
            $query->perPage,
        );

        if ($query->criteria->isNameFragmentOnly()) {
            $this->audit->record(
                action: 'patient.searched',
                actorId: $userId,
                metadata: [
                    'criterion_kinds' => $query->criteria->kinds(),
                    'result_count' => $results->total(),
                    'page' => $results->currentPage(),
                    'cross_facility_lookup' => false,
                ],
            );
        }

        return $results;
    }

    private function actingUserId(): string
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('SearchPatients dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        return (string) $user->getKey();
    }
}
