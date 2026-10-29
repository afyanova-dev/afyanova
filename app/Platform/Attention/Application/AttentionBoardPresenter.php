<?php

declare(strict_types=1);

namespace App\Platform\Attention\Application;

use App\Domains\CareCoordination\Contracts\CareCoordinationApi;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Identity\Contracts\IdentityApi;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Scheduling\Application\Query\GetSchedulableResources;
use App\Domains\Scheduling\Contracts\SchedulingApi;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Shared\Bus\QueryBus;
use Carbon\CarbonImmutable;

/**
 * Composed read model for the Attention board: patients stuck anywhere in
 * the facility, assembled from Encounter, Queueing, Scheduling and
 * Care Coordination contracts plus patient headers. Read-only; every row
 * links out to the workspace that can act on it.
 */
final readonly class AttentionBoardPresenter
{
    private const LONG_WAIT_MINUTES = 120;

    public function __construct(
        private QueryBus $queryBus,
        private SchedulingApi $schedulingApi,
        private PatientApi $patientApi,
        private QueueingApi $queueingApi,
        private CareCoordinationApi $careCoordinationApi,
        private EncounterApi $encounterApi,
        private IdentityApi $identityApi,
    ) {}

    /**
     * @return array{
     *     unqueued: list<array<string, mixed>>,
     *     longWaits: list<array<string, mixed>>,
     *     missed: list<array<string, mixed>>,
     *     referrals: list<array<string, mixed>>,
     *     mineResourceIds: list<string>,
     *     mineUnlinked: bool,
     * }
     */
    public function present(string $facilityId, string $userId, bool $mineOnly): array
    {
        $now = CarbonImmutable::now();

        $myResourceIds = $this->myProviderResourceIds($facilityId, $userId);

        $arrived = $this->safe(fn (): array => $this->encounterApi->activeEncountersForFacility($facilityId, ['ARRIVED'], 200), []);
        $waiting = $this->safe(fn (): array => $this->queueingApi->listWaitingEntries($facilityId, 200), []);
        $appointments = $this->safe(
            fn (): array => $this->schedulingApi->getDailyFacilityAppointments($facilityId, $now),
            [],
        );
        $referrals = $this->safe(fn (): array => $this->careCoordinationApi->searchReferrals($facilityId), []);

        $patientIds = [];
        foreach ($arrived as $e) {
            $patientIds[$e->patientId] = true;
        }
        foreach ($waiting as $e) {
            $patientIds[$e->patientId] = true;
        }
        foreach ($appointments as $a) {
            $patientIds[$a->patientId] = true;
        }
        foreach ($referrals as $r) {
            $patientIds[$r->patientId] = true;
        }

        /** @var array<string, PatientSummary> $patients */
        $patients = $this->safe(
            fn (): array => $this->patientApi->getPatientSummaries(array_keys($patientIds)),
            [],
        );

        $nameOf = static fn (string $patientId): string => $patients[$patientId]->displayName ?? 'Unknown patient';
        $numberOf = static fn (string $patientId): string => $patients[$patientId]->patientNumber ?? '—';

        $queues = [];
        try {
            foreach ($this->queueingApi->listFacilityQueues($facilityId, true) as $queue) {
                $queues[$queue->id] = $queue->name;
            }
        } catch (\Throwable) {
        }

        // Arrived encounters with no active queue entry.
        $unqueued = [];
        foreach ($arrived as $encounter) {
            try {
                $active = $this->queueingApi->getActiveForEncounter($encounter->encounterId);
            } catch (\Throwable) {
                $active = null;
            }
            if ($active !== null) {
                continue;
            }
            $unqueued[] = [
                'patientName' => $nameOf($encounter->patientId),
                'patientNumber' => $numberOf($encounter->patientId),
                'patientId' => $encounter->patientId,
                'encounterNumber' => $encounter->encounterNumber,
                'encounterType' => $encounter->encounterType,
                'arrivedAt' => $encounter->arrivedAt,
            ];
        }

        // Waiting entries at or past the long-wait threshold, worst first.
        $longWaits = [];
        foreach ($waiting as $entry) {
            $minutes = max(0, $now->diffInMinutes($entry->createdAt));
            if ($minutes < self::LONG_WAIT_MINUTES) {
                continue;
            }
            $longWaits[] = [
                'id' => $entry->id,
                'ticketNumber' => $entry->ticketNumber,
                'patientName' => $nameOf($entry->patientId),
                'patientNumber' => $numberOf($entry->patientId),
                'patientId' => $entry->patientId,
                'queue' => $queues[$entry->queueId] ?? '—',
                'queueId' => $entry->queueId,
                'priority' => $entry->priority,
                'waitMinutes' => $minutes,
            ];
        }
        usort($longWaits, static fn (array $a, array $b): int => $b['waitMinutes'] <=> $a['waitMinutes']);

        // Missed appointments today, optionally scoped to the clinician.
        $missed = [];
        foreach ($appointments as $appointment) {
            if ($appointment->status !== 'NO_SHOW') {
                continue;
            }
            if ($mineOnly && ! in_array($appointment->resourceId, $myResourceIds, true)) {
                continue;
            }
            $missed[] = [
                'id' => $appointment->id,
                'patientName' => $nameOf($appointment->patientId),
                'patientNumber' => $numberOf($appointment->patientId),
                'patientId' => $appointment->patientId,
                'time' => $appointment->scheduledStart,
                'mine' => in_array($appointment->resourceId, $myResourceIds, true),
            ];
        }

        // Inbound referrals still awaiting acceptance.
        $pending = [];
        foreach ($referrals as $referral) {
            if ($referral->status !== 'RECEIVED') {
                continue;
            }
            $pending[] = [
                'referralId' => $referral->referralId,
                'patientName' => $nameOf($referral->patientId),
                'patientNumber' => $numberOf($referral->patientId),
                'patientId' => $referral->patientId,
                'priority' => $referral->priority,
            ];
        }

        return [
            'unqueued' => $unqueued,
            'longWaits' => $longWaits,
            'missed' => $missed,
            'referrals' => $pending,
            'mineResourceIds' => $myResourceIds,
            'mineUnlinked' => $mineOnly && $myResourceIds === [],
        ];
    }

    /**
     * @return list<string>
     */
    private function myProviderResourceIds(string $facilityId, string $userId): array
    {
        try {
            $staffRef = $this->identityApi->staffRefForUser($userId);
        } catch (\Throwable) {
            return [];
        }

        if ($staffRef === null || $staffRef === '') {
            return [];
        }

        try {
            $resources = $this->queryBus->dispatch(
                new GetSchedulableResources($facilityId, 'PROVIDER')
            );
        } catch (\Throwable) {
            return [];
        }

        $ids = [];
        foreach ($resources as $resource) {
            if ($resource->resourceRef === $staffRef) {
                $ids[] = $resource->id;
            }
        }

        return $ids;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @param  T  $fallback
     * @return T
     */
    private function safe(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
