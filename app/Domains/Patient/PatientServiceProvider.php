<?php

declare(strict_types=1);

namespace App\Domains\Patient;

use App\Domains\Patient\Application\Command\AddPatientIdentifier;
use App\Domains\Patient\Application\Command\AddPatientIdentifierHandler;
use App\Domains\Patient\Application\Command\ArchivePatientStickyNote;
use App\Domains\Patient\Application\Command\ArchivePatientStickyNoteHandler;
use App\Domains\Patient\Application\Command\CreatePatientStickyNote;
use App\Domains\Patient\Application\Command\CreatePatientStickyNoteHandler;
use App\Domains\Patient\Application\Command\MarkPatientDeceased;
use App\Domains\Patient\Application\Command\MarkPatientDeceasedHandler;
use App\Domains\Patient\Application\Command\MarkPatientInactive;
use App\Domains\Patient\Application\Command\MarkPatientInactiveHandler;
use App\Domains\Patient\Application\Command\MergePatients;
use App\Domains\Patient\Application\Command\MergePatientsHandler;
use App\Domains\Patient\Application\Command\ReactivatePatient;
use App\Domains\Patient\Application\Command\ReactivatePatientHandler;
use App\Domains\Patient\Application\Command\RecordPatientRelationship;
use App\Domains\Patient\Application\Command\RecordPatientRelationshipHandler;
use App\Domains\Patient\Application\Command\RegisterPatient;
use App\Domains\Patient\Application\Command\RegisterPatientAtFacility;
use App\Domains\Patient\Application\Command\RegisterPatientAtFacilityHandler;
use App\Domains\Patient\Application\Command\RegisterPatientHandler;
use App\Domains\Patient\Application\Command\RemovePatientRelationship;
use App\Domains\Patient\Application\Command\RemovePatientRelationshipHandler;
use App\Domains\Patient\Application\Command\RequestUnmerge;
use App\Domains\Patient\Application\Command\RequestUnmergeHandler;
use App\Domains\Patient\Application\Command\UpdatePatient;
use App\Domains\Patient\Application\Command\UpdatePatientAddress;
use App\Domains\Patient\Application\Command\UpdatePatientAddressHandler;
use App\Domains\Patient\Application\Command\UpdatePatientContact;
use App\Domains\Patient\Application\Command\UpdatePatientContactHandler;
use App\Domains\Patient\Application\Command\UpdatePatientHandler;
use App\Domains\Patient\Application\Command\UpdatePatientStickyNote;
use App\Domains\Patient\Application\Command\UpdatePatientStickyNoteHandler;
use App\Domains\Patient\Application\Command\VerifyPatientIdentifier;
use App\Domains\Patient\Application\Command\VerifyPatientIdentifierHandler;
use App\Domains\Patient\Application\PatientApiService;
use App\Domains\Patient\Application\Query\GetPatientGlance;
use App\Domains\Patient\Application\Query\GetPatientGlanceHandler;
use App\Domains\Patient\Application\Query\GetPatientProfile;
use App\Domains\Patient\Application\Query\GetPatientProfileHandler;
use App\Domains\Patient\Application\Query\GetPatientSummaries;
use App\Domains\Patient\Application\Query\GetPatientSummariesHandler;
use App\Domains\Patient\Application\Query\GetPatientSummary;
use App\Domains\Patient\Application\Query\GetPatientSummaryHandler;
use App\Domains\Patient\Application\Query\GetRecentPatients;
use App\Domains\Patient\Application\Query\GetRecentPatientsHandler;
use App\Domains\Patient\Application\Query\SearchPatients;
use App\Domains\Patient\Application\Query\SearchPatientsHandler;
use App\Domains\Patient\Authorization\PatientAddressPolicy;
use App\Domains\Patient\Authorization\PatientContactPolicy;
use App\Domains\Patient\Authorization\PatientIdentifierPolicy;
use App\Domains\Patient\Authorization\PatientPolicy;
use App\Domains\Patient\Authorization\PatientRegistrationPolicy;
use App\Domains\Patient\Authorization\PatientRelationshipPolicy;
use App\Domains\Patient\Authorization\PatientStickyNotePolicy;
use App\Domains\Patient\Console\ClearPatientsCommand;
use App\Domains\Patient\Console\PurgeExpiredRegistrationDraftsCommand;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Patient\Domain\DuplicateCandidateReader;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\Model\PatientAddress;
use App\Domains\Patient\Domain\Model\PatientContact;
use App\Domains\Patient\Domain\Model\PatientIdentifier;
use App\Domains\Patient\Domain\Model\PatientRegistration;
use App\Domains\Patient\Domain\Model\PatientRelationship;
use App\Domains\Patient\Domain\Model\PatientStickyNote;
use App\Domains\Patient\Domain\PatientAddressRepository;
use App\Domains\Patient\Domain\PatientContactRepository;
use App\Domains\Patient\Domain\PatientIdentifierRepository;
use App\Domains\Patient\Domain\PatientIdentityHistoryRepository;
use App\Domains\Patient\Domain\PatientReader;
use App\Domains\Patient\Domain\PatientRegistrationRepository;
use App\Domains\Patient\Domain\PatientRelationshipRepository;
use App\Domains\Patient\Domain\PatientRepository;
use App\Domains\Patient\Domain\PatientSearchReader;
use App\Domains\Patient\Domain\PatientStickyNoteRepository;
use App\Domains\Patient\Infrastructure\Reader\EloquentDuplicateCandidateReader;
use App\Domains\Patient\Infrastructure\Reader\EloquentPatientReader;
use App\Domains\Patient\Infrastructure\Reader\EloquentPatientSearchReader;
use App\Domains\Patient\Infrastructure\Repository\EloquentPatientAddressRepository;
use App\Domains\Patient\Infrastructure\Repository\EloquentPatientContactRepository;
use App\Domains\Patient\Infrastructure\Repository\EloquentPatientIdentifierRepository;
use App\Domains\Patient\Infrastructure\Repository\EloquentPatientIdentityHistoryRepository;
use App\Domains\Patient\Infrastructure\Repository\EloquentPatientRegistrationRepository;
use App\Domains\Patient\Infrastructure\Repository\EloquentPatientRelationshipRepository;
use App\Domains\Patient\Infrastructure\Repository\EloquentPatientRepository;
use App\Domains\Patient\Infrastructure\Repository\EloquentPatientStickyNoteRepository;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Patient module (docs/01-domains/10-patient-domain.md; ADR-066,
 * ADR-114...ADR-126), prefix `pat_`.
 *
 * PHASE 5 is backend/domain contract only (ADR-124): no Presentation/Http, no
 * routes.php, no composed read model — the Reception workspace is PHASE 16.
 *
 * Slice 1: identity core (Patient aggregate) + RegisterPatient end-to-end.
 * Slice 2: facility participation (PatientRegistration aggregate) + the
 * PatientApi cross-domain port (ADR-114, ADR-125).
 * Scope A: lifecycle transitions (ADR-116); external identifiers
 * (PatientIdentifier aggregate) + verified-identifier uniqueness (ADR-117);
 * sensitive-identifier permission key (ADR-122); the RegisterPatient
 * duplicate-detection gate (ADR-117 — BLOCK / REVIEW / ALLOW); patient
 * relationships (PatientRelationship aggregate — ADR-126); secondary contact
 * points (PatientContact aggregate, UpdatePatientContact upsert — ADR-126);
 * addresses (PatientAddress aggregate, UpdatePatientAddress upsert — ADR-126);
 * core demographics (UpdatePatient partial patch on the Patient root — ADR-127);
 * identity-layer merge / unmerge (PatientIdentityHistory aggregate — ADR-128);
 * patient search (SearchPatients query + PatientSearchReader — ADR-129:
 * dedicated PHI-minimal PatientSearchResult, active-facility search set, exact-first
 * deterministic ranking, minimum-criteria 422, MERGED searchable-but-flagged).
 */
final class PatientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PatientRepository::class, EloquentPatientRepository::class);
        $this->app->bind(PatientRegistrationRepository::class, EloquentPatientRegistrationRepository::class);
        $this->app->bind(PatientIdentifierRepository::class, EloquentPatientIdentifierRepository::class);
        $this->app->bind(PatientRelationshipRepository::class, EloquentPatientRelationshipRepository::class);
        $this->app->bind(PatientStickyNoteRepository::class, EloquentPatientStickyNoteRepository::class);
        $this->app->bind(PatientContactRepository::class, EloquentPatientContactRepository::class);
        $this->app->bind(PatientAddressRepository::class, EloquentPatientAddressRepository::class);
        $this->app->bind(PatientIdentityHistoryRepository::class, EloquentPatientIdentityHistoryRepository::class);
        $this->app->bind(PatientReader::class, EloquentPatientReader::class);
        $this->app->bind(PatientSearchReader::class, EloquentPatientSearchReader::class);
        $this->app->bind(DuplicateCandidateReader::class, EloquentDuplicateCandidateReader::class);
        $this->app->bind(PatientApi::class, PatientApiService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        CommandBus::map([
            RegisterPatient::class => RegisterPatientHandler::class,
            UpdatePatient::class => UpdatePatientHandler::class,
            RegisterPatientAtFacility::class => RegisterPatientAtFacilityHandler::class,
            MarkPatientInactive::class => MarkPatientInactiveHandler::class,
            ReactivatePatient::class => ReactivatePatientHandler::class,
            MarkPatientDeceased::class => MarkPatientDeceasedHandler::class,
            AddPatientIdentifier::class => AddPatientIdentifierHandler::class,
            VerifyPatientIdentifier::class => VerifyPatientIdentifierHandler::class,
            RecordPatientRelationship::class => RecordPatientRelationshipHandler::class,
            RemovePatientRelationship::class => RemovePatientRelationshipHandler::class,
            CreatePatientStickyNote::class => CreatePatientStickyNoteHandler::class,
            UpdatePatientStickyNote::class => UpdatePatientStickyNoteHandler::class,
            ArchivePatientStickyNote::class => ArchivePatientStickyNoteHandler::class,
            UpdatePatientContact::class => UpdatePatientContactHandler::class,
            UpdatePatientAddress::class => UpdatePatientAddressHandler::class,
            MergePatients::class => MergePatientsHandler::class,
            RequestUnmerge::class => RequestUnmergeHandler::class,
        ]);

        QueryBus::map([
            GetPatientSummary::class => GetPatientSummaryHandler::class,
            GetPatientSummaries::class => GetPatientSummariesHandler::class,
            GetPatientGlance::class => GetPatientGlanceHandler::class,
            GetPatientProfile::class => GetPatientProfileHandler::class,
            GetRecentPatients::class => GetRecentPatientsHandler::class,
            SearchPatients::class => SearchPatientsHandler::class,
        ]);

        Gate::policy(Patient::class, PatientPolicy::class);
        Gate::policy(PatientRegistration::class, PatientRegistrationPolicy::class);
        Gate::policy(PatientIdentifier::class, PatientIdentifierPolicy::class);
        Gate::policy(PatientRelationship::class, PatientRelationshipPolicy::class);
        Gate::policy(PatientStickyNote::class, PatientStickyNotePolicy::class);
        Gate::policy(PatientContact::class, PatientContactPolicy::class);
        Gate::policy(PatientAddress::class, PatientAddressPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearPatientsCommand::class,
                PurgeExpiredRegistrationDraftsCommand::class,
            ]);
        }

        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
