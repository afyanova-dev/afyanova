<?php

declare(strict_types=1);

use App\Domains\Authentication\AuthenticationServiceProvider;
use App\Domains\BedWard\BedWardServiceProvider;
use App\Domains\Billing\BillingServiceProvider;
use App\Domains\CareCoordination\CareCoordinationServiceProvider;
use App\Domains\Clinical\ClinicalServiceProvider;
use App\Domains\Encounter\EncounterServiceProvider;
use App\Domains\HR\HRServiceProvider;
use App\Domains\Identity\IdentityServiceProvider;
use App\Domains\Insurance\InsuranceServiceProvider;
use App\Domains\Inventory\InventoryServiceProvider;
use App\Domains\Laboratory\LaboratoryServiceProvider;
use App\Domains\Organization\OrganizationServiceProvider;
use App\Domains\Patient\PatientServiceProvider;
use App\Domains\Pharmacy\PharmacyServiceProvider;
use App\Domains\Procurement\ProcurementServiceProvider;
use App\Domains\Radiology\RadiologyServiceProvider;
use App\Domains\Scheduling\SchedulingServiceProvider;
use App\Domains\Theatre\TheatreServiceProvider;
use App\Platform\Attention\AttentionServiceProvider;
use App\Platform\Documents\DocumentsServiceProvider;
use App\Platform\Integration\IntegrationServiceProvider;
use App\Platform\Notifications\NotificationsServiceProvider;
use App\Platform\Queueing\QueueingServiceProvider;
use App\Platform\Reporting\ReportingServiceProvider;
use App\Providers\AppServiceProvider;
use App\Shared\SharedServiceProvider;

/*
 |----------------------------------------------------------------------
 | Provider registration  (IMPLEMENTATION-CONTRACTS.md §2.4)
 |----------------------------------------------------------------------
 | Explicit registration only — never a filesystem scan. Shared kernel
 | is first; one line per domain / platform module, added when the
 | module is scaffolded. ProvidersManifest test asserts every
 | app/Domains/* and app/Platform/* has its line here.
 |
 | Laravel\Fortify\FortifyServiceProvider is auto-discovered (package);
 | AuthenticationServiceProvider layers AfyaNova's config on top (ADR-113).
 */

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,

    // --- Business-truth domains (app/Domains/*) ---
    OrganizationServiceProvider::class,
    IdentityServiceProvider::class,
    AuthenticationServiceProvider::class,
    PatientServiceProvider::class,
    EncounterServiceProvider::class,
    ClinicalServiceProvider::class,
    BedWardServiceProvider::class,
    TheatreServiceProvider::class,
    CareCoordinationServiceProvider::class,
    LaboratoryServiceProvider::class,
    RadiologyServiceProvider::class,
    InventoryServiceProvider::class,
    ProcurementServiceProvider::class,
    PharmacyServiceProvider::class,
    BillingServiceProvider::class,
    InsuranceServiceProvider::class,
    HRServiceProvider::class,
    SchedulingServiceProvider::class,

    // --- Platform services (app/Platform/*) ---
    AttentionServiceProvider::class,
    QueueingServiceProvider::class,
    NotificationsServiceProvider::class,
    DocumentsServiceProvider::class,
    IntegrationServiceProvider::class,
    ReportingServiceProvider::class,
];
