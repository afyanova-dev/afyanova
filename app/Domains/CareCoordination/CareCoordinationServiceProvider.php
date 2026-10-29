<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination;

use App\Domains\CareCoordination\Application\CareCoordinationApiService;
use App\Domains\CareCoordination\Application\Command\AcceptReferral;
use App\Domains\CareCoordination\Contracts\CareCoordinationApi;
use App\Domains\CareCoordination\Application\Command\AcceptReferralHandler;
use App\Domains\CareCoordination\Application\Command\AcknowledgeCareHandover;
use App\Domains\CareCoordination\Application\Command\AcknowledgeCareHandoverHandler;
use App\Domains\CareCoordination\Application\Command\CancelReferral;
use App\Domains\CareCoordination\Application\Command\CancelReferralHandler;
use App\Domains\CareCoordination\Application\Command\CloseCareEpisode;
use App\Domains\CareCoordination\Application\Command\CloseCareEpisodeHandler;
use App\Domains\CareCoordination\Application\Command\CompleteReferral;
use App\Domains\CareCoordination\Application\Command\CompleteReferralFeedback;
use App\Domains\CareCoordination\Application\Command\CompleteReferralFeedbackHandler;
use App\Domains\CareCoordination\Application\Command\CompleteReferralHandler;
use App\Domains\CareCoordination\Application\Command\CreateReferral;
use App\Domains\CareCoordination\Application\Command\CreateReferralHandler;
use App\Domains\CareCoordination\Application\Command\DetachEncounterFromEpisode;
use App\Domains\CareCoordination\Application\Command\DetachEncounterFromEpisodeHandler;
use App\Domains\CareCoordination\Application\Command\ExpireReferral;
use App\Domains\CareCoordination\Application\Command\ExpireReferralHandler;
use App\Domains\CareCoordination\Application\Command\LinkEncounterToEpisode;
use App\Domains\CareCoordination\Application\Command\LinkEncounterToEpisodeHandler;
use App\Domains\CareCoordination\Application\Command\LinkReferralEncounter;
use App\Domains\CareCoordination\Application\Command\LinkReferralEncounterHandler;
use App\Domains\CareCoordination\Application\Command\MarkEpisodeTransferredOut;
use App\Domains\CareCoordination\Application\Command\MarkEpisodeTransferredOutHandler;
use App\Domains\CareCoordination\Application\Command\MarkFeedbackNotProvided;
use App\Domains\CareCoordination\Application\Command\MarkFeedbackNotProvidedHandler;
use App\Domains\CareCoordination\Application\Command\OpenCareEpisode;
use App\Domains\CareCoordination\Application\Command\OpenCareEpisodeHandler;
use App\Domains\CareCoordination\Application\Command\ReceiveReferral;
use App\Domains\CareCoordination\Application\Command\ReceiveReferralHandler;
use App\Domains\CareCoordination\Application\Command\RecordFollowUpOutcome;
use App\Domains\CareCoordination\Application\Command\RecordFollowUpOutcomeHandler;
use App\Domains\CareCoordination\Application\Command\RecordReferralFeedback;
use App\Domains\CareCoordination\Application\Command\RecordReferralFeedbackHandler;
use App\Domains\CareCoordination\Application\Command\RejectReferral;
use App\Domains\CareCoordination\Application\Command\RejectReferralHandler;
use App\Domains\CareCoordination\Application\Command\ScheduleFollowUp;
use App\Domains\CareCoordination\Application\Command\ScheduleFollowUpHandler;
use App\Domains\CareCoordination\Application\Command\SendReferral;
use App\Domains\CareCoordination\Application\Command\SendReferralHandler;
use App\Domains\CareCoordination\Application\Query\GetCareEpisode;
use App\Domains\CareCoordination\Application\Query\GetCareEpisodeHandler;
use App\Domains\CareCoordination\Application\Query\GetEpisodeEncounters;
use App\Domains\CareCoordination\Application\Query\GetEpisodeEncountersHandler;
use App\Domains\CareCoordination\Application\Query\GetFollowUpsDue;
use App\Domains\CareCoordination\Application\Query\GetFollowUpsDueHandler;
use App\Domains\CareCoordination\Application\Query\GetPatientCareEpisodes;
use App\Domains\CareCoordination\Application\Query\GetPatientCareEpisodesHandler;
use App\Domains\CareCoordination\Application\Query\GetPatientReferrals;
use App\Domains\CareCoordination\Application\Query\GetPatientReferralsHandler;
use App\Domains\CareCoordination\Application\Query\GetReferral;
use App\Domains\CareCoordination\Application\Query\GetReferralFeedback;
use App\Domains\CareCoordination\Application\Query\GetReferralFeedbackDue;
use App\Domains\CareCoordination\Application\Query\GetReferralFeedbackDueHandler;
use App\Domains\CareCoordination\Application\Query\GetReferralFeedbackHandler;
use App\Domains\CareCoordination\Application\Query\GetReferralHandler;
use App\Domains\CareCoordination\Application\Query\SearchReferrals;
use App\Domains\CareCoordination\Application\Query\SearchReferralsHandler;
use App\Domains\CareCoordination\Authorization\CareEpisodeEncounterPolicy;
use App\Domains\CareCoordination\Authorization\CareEpisodePolicy;
use App\Domains\CareCoordination\Authorization\FollowUpPolicy;
use App\Domains\CareCoordination\Authorization\ReferralFeedbackPolicy;
use App\Domains\CareCoordination\Authorization\ReferralPolicy;
use App\Domains\CareCoordination\Domain\CareEpisodeEncounterRepository;
use App\Domains\CareCoordination\Domain\CareEpisodeReader;
use App\Domains\CareCoordination\Domain\CareEpisodeRepository;
use App\Domains\CareCoordination\Domain\FollowUpReader;
use App\Domains\CareCoordination\Domain\FollowUpRepository;
use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Domains\CareCoordination\Domain\Model\CareEpisodeEncounter;
use App\Domains\CareCoordination\Domain\Model\FollowUp;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\Model\ReferralFeedback;
use App\Domains\CareCoordination\Domain\ReferralFeedbackReader;
use App\Domains\CareCoordination\Domain\ReferralFeedbackRepository;
use App\Domains\CareCoordination\Domain\ReferralReader;
use App\Domains\CareCoordination\Domain\ReferralRepository;
use App\Domains\CareCoordination\Infrastructure\Reader\EloquentCareEpisodeReader;
use App\Domains\CareCoordination\Infrastructure\Reader\EloquentFollowUpReader;
use App\Domains\CareCoordination\Infrastructure\Reader\EloquentReferralFeedbackReader;
use App\Domains\CareCoordination\Infrastructure\Reader\EloquentReferralReader;
use App\Domains\CareCoordination\Infrastructure\Repository\EloquentCareEpisodeEncounterRepository;
use App\Domains\CareCoordination\Infrastructure\Repository\EloquentCareEpisodeRepository;
use App\Domains\CareCoordination\Infrastructure\Repository\EloquentFollowUpRepository;
use App\Domains\CareCoordination\Infrastructure\Repository\EloquentReferralFeedbackRepository;
use App\Domains\CareCoordination\Infrastructure\Repository\EloquentReferralRepository;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Care Coordination module service provider (26-care-coordination-domain.md;
 * ADR-151). CC.1: `Referral` + `ReferralFeedback` only.
 */
final class CareCoordinationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReferralRepository::class, EloquentReferralRepository::class);
        $this->app->bind(ReferralFeedbackRepository::class, EloquentReferralFeedbackRepository::class);
        $this->app->bind(CareEpisodeRepository::class, EloquentCareEpisodeRepository::class);
        $this->app->bind(CareEpisodeEncounterRepository::class, EloquentCareEpisodeEncounterRepository::class);
        $this->app->bind(FollowUpRepository::class, EloquentFollowUpRepository::class);

        $this->app->bind(ReferralReader::class, EloquentReferralReader::class);
        $this->app->bind(ReferralFeedbackReader::class, EloquentReferralFeedbackReader::class);
        $this->app->bind(CareEpisodeReader::class, EloquentCareEpisodeReader::class);
        $this->app->bind(FollowUpReader::class, EloquentFollowUpReader::class);
        $this->app->bind(CareCoordinationApi::class, CareCoordinationApiService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        CommandBus::map([
            CreateReferral::class => CreateReferralHandler::class,
            SendReferral::class => SendReferralHandler::class,
            CancelReferral::class => CancelReferralHandler::class,
            ReceiveReferral::class => ReceiveReferralHandler::class,
            AcceptReferral::class => AcceptReferralHandler::class,
            RejectReferral::class => RejectReferralHandler::class,
            CompleteReferral::class => CompleteReferralHandler::class,
            ExpireReferral::class => ExpireReferralHandler::class,
            LinkReferralEncounter::class => LinkReferralEncounterHandler::class,
            AcknowledgeCareHandover::class => AcknowledgeCareHandoverHandler::class,
            RecordReferralFeedback::class => RecordReferralFeedbackHandler::class,
            CompleteReferralFeedback::class => CompleteReferralFeedbackHandler::class,
            MarkFeedbackNotProvided::class => MarkFeedbackNotProvidedHandler::class,
            OpenCareEpisode::class => OpenCareEpisodeHandler::class,
            CloseCareEpisode::class => CloseCareEpisodeHandler::class,
            MarkEpisodeTransferredOut::class => MarkEpisodeTransferredOutHandler::class,
            LinkEncounterToEpisode::class => LinkEncounterToEpisodeHandler::class,
            DetachEncounterFromEpisode::class => DetachEncounterFromEpisodeHandler::class,
            ScheduleFollowUp::class => ScheduleFollowUpHandler::class,
            RecordFollowUpOutcome::class => RecordFollowUpOutcomeHandler::class,
        ]);

        QueryBus::map([
            GetReferral::class => GetReferralHandler::class,
            SearchReferrals::class => SearchReferralsHandler::class,
            GetReferralFeedback::class => GetReferralFeedbackHandler::class,
            GetPatientReferrals::class => GetPatientReferralsHandler::class,
            GetReferralFeedbackDue::class => GetReferralFeedbackDueHandler::class,
            GetCareEpisode::class => GetCareEpisodeHandler::class,
            GetPatientCareEpisodes::class => GetPatientCareEpisodesHandler::class,
            GetEpisodeEncounters::class => GetEpisodeEncountersHandler::class,
            GetFollowUpsDue::class => GetFollowUpsDueHandler::class,
        ]);

        Gate::policy(Referral::class, ReferralPolicy::class);
        Gate::policy(ReferralFeedback::class, ReferralFeedbackPolicy::class);
        Gate::policy(CareEpisode::class, CareEpisodePolicy::class);
        Gate::policy(CareEpisodeEncounter::class, CareEpisodeEncounterPolicy::class);
        Gate::policy(FollowUp::class, FollowUpPolicy::class);
    }
}
