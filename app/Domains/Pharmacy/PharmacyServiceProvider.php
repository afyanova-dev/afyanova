<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy;

use App\Domains\Clinical\Contracts\Event\PrescriptionIssued;
use App\Domains\Pharmacy\Application\Command\AcceptPrescription;
use App\Domains\Pharmacy\Application\Command\AcceptPrescriptionHandler;
use App\Domains\Pharmacy\Application\Command\CancelPharmacyOrder;
use App\Domains\Pharmacy\Application\Command\CancelPharmacyOrderHandler;
use App\Domains\Pharmacy\Application\Command\CancelPickup;
use App\Domains\Pharmacy\Application\Command\CancelPickupHandler;
use App\Domains\Pharmacy\Application\Command\CancelPrescription;
use App\Domains\Pharmacy\Application\Command\CancelPrescriptionHandler;
use App\Domains\Pharmacy\Application\Command\CreatePharmacyOrder;
use App\Domains\Pharmacy\Application\Command\CreatePharmacyOrderHandler;
use App\Domains\Pharmacy\Application\Command\Dispense;
use App\Domains\Pharmacy\Application\Command\DispenseHandler;
use App\Domains\Pharmacy\Application\Command\HoldPharmacyOrder;
use App\Domains\Pharmacy\Application\Command\HoldPharmacyOrderHandler;
use App\Domains\Pharmacy\Application\Command\IngestPrescription;
use App\Domains\Pharmacy\Application\Command\IngestPrescriptionHandler;
use App\Domains\Pharmacy\Application\Command\TranscribePaperPrescription;
use App\Domains\Pharmacy\Application\Command\TranscribePaperPrescriptionHandler;
use App\Domains\Pharmacy\Application\Command\AcceptTranscription;
use App\Domains\Pharmacy\Application\Command\AcceptTranscriptionHandler;
use App\Domains\Pharmacy\Application\Command\MarkPickupNotCollected;
use App\Domains\Pharmacy\Application\Command\MarkPickupNotCollectedHandler;
use App\Domains\Pharmacy\Application\Command\RecordPickup;
use App\Domains\Pharmacy\Application\Command\RecordPickupHandler;
use App\Domains\Pharmacy\Application\Command\RejectPharmacyOrder;
use App\Domains\Pharmacy\Application\Command\RejectPharmacyOrderHandler;
use App\Domains\Pharmacy\Application\Command\ReleasePharmacyOrderHold;
use App\Domains\Pharmacy\Application\Command\ReleasePharmacyOrderHoldHandler;
use App\Domains\Pharmacy\Application\Command\ReverseDispensing;
use App\Domains\Pharmacy\Application\Command\ReverseDispensingHandler;
use App\Domains\Pharmacy\Application\Command\ValidatePharmacyOrder;
use App\Domains\Pharmacy\Application\Command\ValidatePharmacyOrderHandler;
use App\Domains\Pharmacy\Application\Listener\OnPrescriptionIssued;
use App\Domains\Pharmacy\Application\Query\GetDispensing;
use App\Domains\Pharmacy\Application\Query\GetDispensingHandler;
use App\Domains\Pharmacy\Application\Query\GetPatientPrescriptions;
use App\Domains\Pharmacy\Application\Query\GetPatientPrescriptionsHandler;
use App\Domains\Pharmacy\Application\Query\GetPharmacyOrder;
use App\Domains\Pharmacy\Application\Query\GetPharmacyOrderDispensings;
use App\Domains\Pharmacy\Application\Query\GetPharmacyOrderDispensingsHandler;
use App\Domains\Pharmacy\Application\Query\GetPharmacyOrderHandler;
use App\Domains\Pharmacy\Application\Query\GetPickup;
use App\Domains\Pharmacy\Application\Query\GetPickupHandler;
use App\Domains\Pharmacy\Application\Query\GetPrescription;
use App\Domains\Pharmacy\Application\Query\GetPrescriptionHandler;
use App\Domains\Pharmacy\Application\Query\SearchPharmacyOrders;
use App\Domains\Pharmacy\Application\Query\SearchPharmacyOrdersHandler;
use App\Domains\Pharmacy\Application\Query\SearchPickups;
use App\Domains\Pharmacy\Application\Query\SearchPickupsHandler;
use App\Domains\Pharmacy\Application\Query\SearchPrescriptions;
use App\Domains\Pharmacy\Application\Query\SearchPrescriptionsHandler;
use App\Domains\Pharmacy\Authorization\PharmacyOrderPolicy;
use App\Domains\Pharmacy\Authorization\PrescriptionPolicy;
use App\Domains\Pharmacy\Domain\DispensingReader;
use App\Domains\Pharmacy\Domain\DispensingRepository;
use App\Domains\Pharmacy\Domain\DispensingReversalRepository;
use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Domains\Pharmacy\Domain\PharmacyOrderReader;
use App\Domains\Pharmacy\Domain\PharmacyOrderRepository;
use App\Domains\Pharmacy\Domain\PickupReader;
use App\Domains\Pharmacy\Domain\PickupRepository;
use App\Domains\Pharmacy\Domain\PrescriptionReader;
use App\Domains\Pharmacy\Domain\PrescriptionRepository;
use App\Domains\Pharmacy\Infrastructure\Reader\EloquentDispensingReader;
use App\Domains\Pharmacy\Infrastructure\Reader\EloquentPharmacyOrderReader;
use App\Domains\Pharmacy\Infrastructure\Reader\EloquentPickupReader;
use App\Domains\Pharmacy\Infrastructure\Reader\EloquentPrescriptionReader;
use App\Domains\Pharmacy\Infrastructure\Repository\EloquentDispensingRepository;
use App\Domains\Pharmacy\Infrastructure\Repository\EloquentDispensingReversalRepository;
use App\Domains\Pharmacy\Infrastructure\Repository\EloquentPharmacyOrderRepository;
use App\Domains\Pharmacy\Infrastructure\Repository\EloquentPickupRepository;
use App\Domains\Pharmacy\Infrastructure\Repository\EloquentPrescriptionRepository;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Events\EventRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Pharmacy module service provider (16-pharmacy-domain.md; ADR-181, ADR-182).
 *
 * Pharm.1 (ADR-181): the Pharmacy domain frame + the Prescription RECORD, intake
 * → ACTIVE. `OnPrescriptionIssued` (a listener on `clinical.PrescriptionIssued` —
 * the 2nd `EventRegistry::listen` in the engagement) → `IngestPrescription`
 * (system) writes a PHI-minimal `RECEIVED` stub; an authenticated
 * `AcceptPrescription` fetches the decision through the PROTECTED
 * `ClinicalApi::getPrescribingDecision` and writes the immutable clinical
 * snapshot → `ACTIVE`; `CancelPrescription` → `CANCELLED`.
 *
 * Pharm.2a (ADR-182): the `PharmacyOrder` aggregate + the pharmacist validation
 * workflow. `CreatePharmacyOrder` (from an ACTIVE prescription) → RECEIVED →
 * `ValidatePharmacyOrder` (attestation) → VALIDATED; `RejectPharmacyOrder` /
 * `HoldPharmacyOrder` / `ReleasePharmacyOrderHold` / `CancelPharmacyOrder`.
 * `RXO-%08d` numbers; `pharmacy.PharmacyOrderValidated` / `…Rejected` /
 * `…Cancelled` events (no Pharm.2a consumer — Pharm.3 dispensing subscribes).
 * ZERO cross-domain — the prescription loads via Pharmacy's own repo.
 *
 * Dispensing (+ `InventoryApi::reserveStock` / `getAvailableStock`), product
 * selection, partial dispensing, substitution, returns, the medication catalog,
 * formulary, dispensing safety, the financial gate and billing events are
 * deferred to Pharm.3+.
 */
final class PharmacyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PrescriptionRepository::class, EloquentPrescriptionRepository::class);
        $this->app->bind(PrescriptionReader::class, EloquentPrescriptionReader::class);

        $this->app->bind(PharmacyOrderRepository::class, EloquentPharmacyOrderRepository::class);
        $this->app->bind(PharmacyOrderReader::class, EloquentPharmacyOrderReader::class);

        // Pharm.3 — Dispensing (ADR-184).
        $this->app->bind(DispensingRepository::class, EloquentDispensingRepository::class);
        $this->app->bind(DispensingReader::class, EloquentDispensingReader::class);
        // Pharm.3b — Dispensing reversal (ADR-185).
        $this->app->bind(DispensingReversalRepository::class, EloquentDispensingReversalRepository::class);

        // Pharm.3c — Medication pickup (ADR-186).
        $this->app->bind(PickupRepository::class, EloquentPickupRepository::class);
        $this->app->bind(PickupReader::class, EloquentPickupReader::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        CommandBus::map([
            // Pharm.1 — the Prescription record (ADR-181 D-E).
            IngestPrescription::class => IngestPrescriptionHandler::class,
            AcceptPrescription::class => AcceptPrescriptionHandler::class,
            CancelPrescription::class => CancelPrescriptionHandler::class,

            // Pharm.4 — paper-transcription origination.
            TranscribePaperPrescription::class => TranscribePaperPrescriptionHandler::class,
            AcceptTranscription::class => AcceptTranscriptionHandler::class,

            // Pharm.2a — the Pharmacy Order + validation workflow (ADR-182 D-D).
            CreatePharmacyOrder::class => CreatePharmacyOrderHandler::class,
            ValidatePharmacyOrder::class => ValidatePharmacyOrderHandler::class,
            RejectPharmacyOrder::class => RejectPharmacyOrderHandler::class,
            HoldPharmacyOrder::class => HoldPharmacyOrderHandler::class,
            ReleasePharmacyOrderHold::class => ReleasePharmacyOrderHoldHandler::class,
            CancelPharmacyOrder::class => CancelPharmacyOrderHandler::class,

            // Pharm.3 — Dispensing (ADR-184 D-H).
            Dispense::class => DispenseHandler::class,

            // Pharm.3b — Dispensing reversal (ADR-185 D-D).
            ReverseDispensing::class => ReverseDispensingHandler::class,

            // Pharm.3c — Medication pickup (ADR-186 D-D).
            RecordPickup::class => RecordPickupHandler::class,
            MarkPickupNotCollected::class => MarkPickupNotCollectedHandler::class,
            CancelPickup::class => CancelPickupHandler::class,
        ]);

        // Pharm.1 — the Clinical → Pharmacy handoff (ADR-136 / ADR-181 D-B).
        EventRegistry::listen(PrescriptionIssued::class, OnPrescriptionIssued::class);

        QueryBus::map([
            GetPrescription::class => GetPrescriptionHandler::class,
            GetPatientPrescriptions::class => GetPatientPrescriptionsHandler::class,
            SearchPrescriptions::class => SearchPrescriptionsHandler::class,

            // Pharm.2a (ADR-182 D-E).
            GetPharmacyOrder::class => GetPharmacyOrderHandler::class,
            SearchPharmacyOrders::class => SearchPharmacyOrdersHandler::class,

            // Pharm.3 (ADR-184 D-I).
            GetDispensing::class => GetDispensingHandler::class,
            GetPharmacyOrderDispensings::class => GetPharmacyOrderDispensingsHandler::class,

            // Pharm.3c (ADR-186 D-F).
            GetPickup::class => GetPickupHandler::class,
            SearchPickups::class => SearchPickupsHandler::class,
        ]);

        Gate::policy(Prescription::class, PrescriptionPolicy::class);
        Gate::policy(PharmacyOrder::class, PharmacyOrderPolicy::class);

        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
