<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Radiology\Application\Command\AcceptImagingOrder;
use App\Domains\Radiology\Application\Command\CompleteImagingStudy;
use App\Domains\Radiology\Application\Command\CreateImagingOrder;
use App\Domains\Radiology\Application\Command\CreateRadiologyReport;
use App\Domains\Radiology\Application\Command\ReleaseRadiologyReport;
use App\Domains\Radiology\Application\Command\ScheduleImagingStudy;
use App\Domains\Radiology\Application\Command\StartImagingStudy;
use App\Domains\Radiology\Application\Command\SubmitImagingOrder;
use App\Domains\Radiology\Application\Command\VerifyRadiologyReport;
use App\Domains\Radiology\Domain\Model\ImagingModality;
use App\Domains\Radiology\Domain\Model\ImagingOrderItem;
use App\Domains\Radiology\Domain\Model\ImagingService;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

if (! function_exists('radiologyFixture')) {
    /**
     * @param  list<string>|null  $permissions
     * @param  list<string>  $capabilities
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function radiologyFixture(?array $permissions = null, array $capabilities = ['OUTPATIENT_CARE']): array
    {
        $permissions ??= [
            'radiology.access',
            'radiology.orders.create',
            'radiology.orders.accept',
            'radiology.studies.perform',
            'radiology.reports.create',
            'radiology.reports.verify',
            'radiology.reports.release',
            'radiology.reports.amend',
            'radiology.critical.manage',
            'encounter.encounters.read',
            'encounter.encounters.create',
            'encounter.encounters.update',
            'encounter.encounters.cancel',
            'patient.patients.read',
        ];

        /** @var array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string} $fixture */
        $fixture = makeTenantWithUser($permissions, $capabilities);
        test()->actingAs($fixture['user']);

        return $fixture;
    }
}

if (! function_exists('seedImagingModality')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function seedImagingModality(string $tenantId, array $overrides = []): ImagingModality
    {
        return TenantContextScope::run($tenantId, null, fn () => ImagingModality::factory()
            ->forTenant($tenantId)
            ->create($overrides));
    }
}

if (! function_exists('seedImagingService')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function seedImagingService(string $tenantId, ?string $modalityId = null, array $overrides = []): ImagingService
    {
        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $modalityId, $overrides): ImagingService {
            $modalityId ??= (string) seedImagingModality($tenantId)->id;

            return ImagingService::factory()->forTenant($tenantId)->forModality($modalityId)->create($overrides);
        });
    }
}

if (! function_exists('seedActiveRadiologyEncounter')) {
    /**
     * Create an ARRIVED-or-ACTIVE encounter eligible for an imaging order.
     *
     * @return array{encounterId: string, patientId: string}
     */
    function seedActiveRadiologyEncounter(string $tenantId, string $facilityId, string $actorId, bool $started = true, bool $planned = false): array
    {
        $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
            ->forTenant($tenantId)
            ->create()->id);

        $encounterId = (string) Str::uuid7();

        TenantContextScope::run($tenantId, $facilityId, function () use ($encounterId, $patientId, $actorId, $started, $planned): void {
            app(CommandBus::class)->dispatch(new CreateEncounter(
                encounterId: $encounterId,
                patientId: $patientId,
                actorId: $actorId,
                encounterType: EncounterType::Outpatient,
                planned: $planned,
            ));

            if ($started && ! $planned) {
                app(CommandBus::class)->dispatch(new StartEncounter(
                    encounterId: $encounterId,
                    actorId: $actorId,
                ));
            }
        });

        return ['encounterId' => $encounterId, 'patientId' => $patientId];
    }
}

if (! function_exists('seedAcceptedImagingOrder')) {
    /**
     * Create an imaging order and drive it to ACCEPTED. Modality / service codes
     * are unique per call so a test may seed several.
     *
     * @return array{orderId: string, encounterId: string, patientId: string, serviceId: string, modalityId: string, modalityCode: string, serviceCode: string}
     */
    function seedAcceptedImagingOrder(string $tenantId, string $facilityId, string $actorId): array
    {
        $suffix = Str::upper(Str::random(5));
        $modalityCode = 'XR-'.$suffix;
        $serviceCode = 'XR-CHEST-'.$suffix;

        $modality = seedImagingModality($tenantId, ['code' => $modalityCode, 'name' => 'X-Ray']);
        $service = seedImagingService($tenantId, (string) $modality->id, ['code' => $serviceCode, 'name' => 'Chest X-Ray']);
        ['encounterId' => $encounterId, 'patientId' => $patientId] =
            seedActiveRadiologyEncounter($tenantId, $facilityId, $actorId);

        $orderId = (string) Str::uuid7();
        TenantContextScope::run($tenantId, $facilityId, function () use ($orderId, $actorId, $patientId, $encounterId, $service): void {
            $bus = app(CommandBus::class);
            $bus->dispatch(new CreateImagingOrder(
                orderId: $orderId,
                actorId: $actorId,
                patientId: $patientId,
                encounterId: $encounterId,
                serviceIds: [(string) $service->id],
            ));
            $bus->dispatch(new SubmitImagingOrder(orderId: $orderId, actorId: $actorId));
            $bus->dispatch(new AcceptImagingOrder(orderId: $orderId, actorId: $actorId));
        });

        return [
            'orderId' => $orderId,
            'encounterId' => $encounterId,
            'patientId' => $patientId,
            'serviceId' => (string) $service->id,
            'modalityId' => (string) $modality->id,
            'modalityCode' => $modality->code,
            'serviceCode' => $service->code,
        ];
    }
}

if (! function_exists('seedScheduledImagingStudy')) {
    /**
     * Create an accepted imaging order and schedule its first study.
     *
     * @return array{studyId: string, orderId: string, orderItemId: string, patientId: string, encounterId: string, modalityCode: string, serviceCode: string}
     */
    function seedScheduledImagingStudy(string $tenantId, string $facilityId, string $actorId): array
    {
        ['orderId' => $orderId, 'patientId' => $patientId, 'encounterId' => $encounterId, 'modalityCode' => $modalityCode, 'serviceCode' => $serviceCode] =
            seedAcceptedImagingOrder($tenantId, $facilityId, $actorId);

        $orderItemId = (string) TenantContextScope::run($tenantId, $facilityId, fn () => ImagingOrderItem::query()->where('order_id', $orderId)->value('id'));
        $studyId = (string) Str::uuid7();

        TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new ScheduleImagingStudy(
            studyId: $studyId,
            orderId: $orderId,
            orderItemId: $orderItemId,
            actorId: $actorId,
        )));

        return [
            'studyId' => $studyId,
            'orderId' => $orderId,
            'orderItemId' => $orderItemId,
            'patientId' => $patientId,
            'encounterId' => $encounterId,
            'modalityCode' => $modalityCode,
            'serviceCode' => $serviceCode,
        ];
    }
}

if (! function_exists('seedCompletedImagingStudy')) {
    /**
     * Schedule → start → complete a study, ready for a radiology report.
     *
     * @return array{studyId: string, orderId: string, orderItemId: string, patientId: string, encounterId: string, modalityCode: string, serviceCode: string}
     */
    function seedCompletedImagingStudy(string $tenantId, string $facilityId, string $actorId): array
    {
        $seed = seedScheduledImagingStudy($tenantId, $facilityId, $actorId);

        TenantContextScope::run($tenantId, $facilityId, function () use ($seed, $actorId): void {
            $bus = app(CommandBus::class);
            $bus->dispatch(new StartImagingStudy(studyId: $seed['studyId'], actorId: $actorId, patientVerified: true));
            $bus->dispatch(new CompleteImagingStudy(studyId: $seed['studyId'], actorId: $actorId));
        });

        return $seed;
    }
}

if (! function_exists('seedDraftRadiologyReport')) {
    /**
     * Create a completed study and its DRAFT report.
     *
     * @return array{reportId: string, studyId: string, orderId: string, patientId: string}
     */
    function seedDraftRadiologyReport(string $tenantId, string $facilityId, string $actorId): array
    {
        ['studyId' => $studyId, 'orderId' => $orderId, 'patientId' => $patientId] =
            seedCompletedImagingStudy($tenantId, $facilityId, $actorId);

        $reportId = (string) Str::uuid7();
        TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new CreateRadiologyReport(
            reportId: $reportId,
            studyId: $studyId,
            actorId: $actorId,
            findingsText: 'Right lower-lobe opacity.',
            impressionText: 'Consistent with pneumonia.',
        )));

        return ['reportId' => $reportId, 'studyId' => $studyId, 'orderId' => $orderId, 'patientId' => $patientId];
    }
}

if (! function_exists('seedVerifiedRadiologyReport')) {
    /**
     * @return array{reportId: string, studyId: string, orderId: string, patientId: string}
     */
    function seedVerifiedRadiologyReport(string $tenantId, string $facilityId, string $actorId): array
    {
        $seed = seedDraftRadiologyReport($tenantId, $facilityId, $actorId);

        TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
            new VerifyRadiologyReport(reportId: $seed['reportId'], actorId: $actorId),
        ));

        return $seed;
    }
}

if (! function_exists('seedReleasedRadiologyReport')) {
    /**
     * @return array{reportId: string, studyId: string, orderId: string, patientId: string}
     */
    function seedReleasedRadiologyReport(string $tenantId, string $facilityId, string $actorId): array
    {
        $seed = seedVerifiedRadiologyReport($tenantId, $facilityId, $actorId);

        TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
            new ReleaseRadiologyReport(reportId: $seed['reportId'], actorId: $actorId),
        ));

        return $seed;
    }
}
