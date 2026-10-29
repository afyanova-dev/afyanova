<?php

declare(strict_types=1);

namespace App\Domains\Theatre;

use App\Domains\Theatre\Application\Command\AbortCase;
use App\Domains\Theatre\Application\Command\AbortCaseHandler;
use App\Domains\Theatre\Application\Command\AbortProcedure;
use App\Domains\Theatre\Application\Command\AbortProcedureHandler;
use App\Domains\Theatre\Application\Command\AcknowledgeRecoveryHandoff;
use App\Domains\Theatre\Application\Command\AcknowledgeRecoveryHandoffHandler;
use App\Domains\Theatre\Application\Command\AmendAnaesthesiaRecord;
use App\Domains\Theatre\Application\Command\AmendAnaesthesiaRecordHandler;
use App\Domains\Theatre\Application\Command\AmendOperativeNote;
use App\Domains\Theatre\Application\Command\AmendOperativeNoteHandler;
use App\Domains\Theatre\Application\Command\AssignOperatingRoom;
use App\Domains\Theatre\Application\Command\AssignOperatingRoomHandler;
use App\Domains\Theatre\Application\Command\AssignTeamMember;
use App\Domains\Theatre\Application\Command\AssignTeamMemberHandler;
use App\Domains\Theatre\Application\Command\CancelCase;
use App\Domains\Theatre\Application\Command\CancelCaseHandler;
use App\Domains\Theatre\Application\Command\CompleteCase;
use App\Domains\Theatre\Application\Command\CompleteCaseHandler;
use App\Domains\Theatre\Application\Command\CompleteChecklistStage;
use App\Domains\Theatre\Application\Command\CompleteChecklistStageHandler;
use App\Domains\Theatre\Application\Command\CompleteProcedure;
use App\Domains\Theatre\Application\Command\CompleteProcedureHandler;
use App\Domains\Theatre\Application\Command\CreateOperatingRoom;
use App\Domains\Theatre\Application\Command\CreateOperatingRoomHandler;
use App\Domains\Theatre\Application\Command\CreateOperativeNote;
use App\Domains\Theatre\Application\Command\CreateOperativeNoteHandler;
use App\Domains\Theatre\Application\Command\CreateSurgicalCase;
use App\Domains\Theatre\Application\Command\CreateSurgicalCaseHandler;
use App\Domains\Theatre\Application\Command\HandOffSurgicalSpecimen;
use App\Domains\Theatre\Application\Command\HandOffSurgicalSpecimenHandler;
use App\Domains\Theatre\Application\Command\InitiateRecoveryHandoff;
use App\Domains\Theatre\Application\Command\InitiateRecoveryHandoffHandler;
use App\Domains\Theatre\Application\Command\LabelSurgicalSpecimen;
use App\Domains\Theatre\Application\Command\LabelSurgicalSpecimenHandler;
use App\Domains\Theatre\Application\Command\MarkCaseReady;
use App\Domains\Theatre\Application\Command\MarkCaseReadyHandler;
use App\Domains\Theatre\Application\Command\MarkNoShow;
use App\Domains\Theatre\Application\Command\MarkNoShowHandler;
use App\Domains\Theatre\Application\Command\MarkSurgicalSpecimenReceived;
use App\Domains\Theatre\Application\Command\MarkSurgicalSpecimenReceivedHandler;
use App\Domains\Theatre\Application\Command\PostponeCase;
use App\Domains\Theatre\Application\Command\PostponeCaseHandler;
use App\Domains\Theatre\Application\Command\RecordAnaesthesia;
use App\Domains\Theatre\Application\Command\RecordAnaesthesiaHandler;
use App\Domains\Theatre\Application\Command\RecordCaseEvent;
use App\Domains\Theatre\Application\Command\RecordCaseEventHandler;
use App\Domains\Theatre\Application\Command\RecordPlannedProcedure;
use App\Domains\Theatre\Application\Command\RecordPlannedProcedureHandler;
use App\Domains\Theatre\Application\Command\RecordSurgicalSpecimen;
use App\Domains\Theatre\Application\Command\RecordSurgicalSpecimenHandler;
use App\Domains\Theatre\Application\Command\ReplaceTeamMember;
use App\Domains\Theatre\Application\Command\ReplaceTeamMemberHandler;
use App\Domains\Theatre\Application\Command\RescheduleSurgicalCase;
use App\Domains\Theatre\Application\Command\RescheduleSurgicalCaseHandler;
use App\Domains\Theatre\Application\Command\ReviewOperativeNote;
use App\Domains\Theatre\Application\Command\ReviewOperativeNoteHandler;
use App\Domains\Theatre\Application\Command\ScheduleSurgicalCase;
use App\Domains\Theatre\Application\Command\ScheduleSurgicalCaseHandler;
use App\Domains\Theatre\Application\Command\SignAnaesthesiaRecord;
use App\Domains\Theatre\Application\Command\SignAnaesthesiaRecordHandler;
use App\Domains\Theatre\Application\Command\SignOperativeNote;
use App\Domains\Theatre\Application\Command\SignOperativeNoteHandler;
use App\Domains\Theatre\Application\Command\StartCase;
use App\Domains\Theatre\Application\Command\StartCaseHandler;
use App\Domains\Theatre\Application\Command\StartPreOp;
use App\Domains\Theatre\Application\Command\StartPreOpHandler;
use App\Domains\Theatre\Application\Command\StartProcedure;
use App\Domains\Theatre\Application\Command\StartProcedureHandler;
use App\Domains\Theatre\Application\Command\UpdateAnaesthesiaContent;
use App\Domains\Theatre\Application\Command\UpdateAnaesthesiaContentHandler;
use App\Domains\Theatre\Application\Command\UpdateOperativeNoteContent;
use App\Domains\Theatre\Application\Command\UpdateOperativeNoteContentHandler;
use App\Domains\Theatre\Application\Query\GetActiveCases;
use App\Domains\Theatre\Application\Query\GetActiveCasesHandler;
use App\Domains\Theatre\Application\Query\GetAnaesthesiaRecord;
use App\Domains\Theatre\Application\Query\GetAnaesthesiaRecordForCase;
use App\Domains\Theatre\Application\Query\GetAnaesthesiaRecordForCaseHandler;
use App\Domains\Theatre\Application\Query\GetAnaesthesiaRecordHandler;
use App\Domains\Theatre\Application\Query\GetCaseEvents;
use App\Domains\Theatre\Application\Query\GetCaseEventsHandler;
use App\Domains\Theatre\Application\Query\GetCaseSpecimens;
use App\Domains\Theatre\Application\Query\GetCaseSpecimensHandler;
use App\Domains\Theatre\Application\Query\GetOperatingRoom;
use App\Domains\Theatre\Application\Query\GetOperatingRoomHandler;
use App\Domains\Theatre\Application\Query\GetOperativeNote;
use App\Domains\Theatre\Application\Query\GetOperativeNoteForCase;
use App\Domains\Theatre\Application\Query\GetOperativeNoteForCaseHandler;
use App\Domains\Theatre\Application\Query\GetOperativeNoteHandler;
use App\Domains\Theatre\Application\Query\GetOrBoard;
use App\Domains\Theatre\Application\Query\GetOrBoardHandler;
use App\Domains\Theatre\Application\Query\GetProceduresForCase;
use App\Domains\Theatre\Application\Query\GetProceduresForCaseHandler;
use App\Domains\Theatre\Application\Query\GetRecoveryHandoffForCase;
use App\Domains\Theatre\Application\Query\GetRecoveryHandoffForCaseHandler;
use App\Domains\Theatre\Application\Query\GetSurgicalCase;
use App\Domains\Theatre\Application\Query\GetSurgicalCaseHandler;
use App\Domains\Theatre\Application\Query\GetSurgicalSpecimen;
use App\Domains\Theatre\Application\Query\GetSurgicalSpecimenHandler;
use App\Domains\Theatre\Authorization\AnaesthesiaRecordPolicy;
use App\Domains\Theatre\Authorization\CaseEventPolicy;
use App\Domains\Theatre\Authorization\OperatingRoomPolicy;
use App\Domains\Theatre\Authorization\OperativeNotePolicy;
use App\Domains\Theatre\Authorization\RecoveryHandoffPolicy;
use App\Domains\Theatre\Authorization\SurgicalCasePolicy;
use App\Domains\Theatre\Authorization\SurgicalProcedurePolicy;
use App\Domains\Theatre\Authorization\SurgicalSpecimenPolicy;
use App\Domains\Theatre\Domain\AnaesthesiaRecordReader;
use App\Domains\Theatre\Domain\AnaesthesiaRecordRepository;
use App\Domains\Theatre\Domain\CaseEventReader;
use App\Domains\Theatre\Domain\CaseEventRepository;
use App\Domains\Theatre\Domain\Model\AnaesthesiaRecord;
use App\Domains\Theatre\Domain\Model\CaseEvent;
use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\Model\OperativeNote;
use App\Domains\Theatre\Domain\Model\RecoveryHandoff;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\Model\SurgicalProcedure;
use App\Domains\Theatre\Domain\Model\SurgicalSpecimen;
use App\Domains\Theatre\Domain\OperatingRoomReader;
use App\Domains\Theatre\Domain\OperatingRoomRepository;
use App\Domains\Theatre\Domain\OperativeNoteReader;
use App\Domains\Theatre\Domain\OperativeNoteRepository;
use App\Domains\Theatre\Domain\RecoveryHandoffReader;
use App\Domains\Theatre\Domain\RecoveryHandoffRepository;
use App\Domains\Theatre\Domain\SurgicalCaseReader;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;
use App\Domains\Theatre\Domain\SurgicalProcedureReader;
use App\Domains\Theatre\Domain\SurgicalProcedureRepository;
use App\Domains\Theatre\Domain\SurgicalSpecimenReader;
use App\Domains\Theatre\Domain\SurgicalSpecimenRepository;
use App\Domains\Theatre\Infrastructure\Reader\EloquentAnaesthesiaRecordReader;
use App\Domains\Theatre\Infrastructure\Reader\EloquentCaseEventReader;
use App\Domains\Theatre\Infrastructure\Reader\EloquentOperatingRoomReader;
/**
 * Theatre module service provider (17-theatre-domain.md; ADR-150).
 */
use App\Domains\Theatre\Infrastructure\Reader\EloquentOperativeNoteReader;
use App\Domains\Theatre\Infrastructure\Reader\EloquentRecoveryHandoffReader;
use App\Domains\Theatre\Infrastructure\Reader\EloquentSurgicalCaseReader;
use App\Domains\Theatre\Infrastructure\Reader\EloquentSurgicalProcedureReader;
use App\Domains\Theatre\Infrastructure\Reader\EloquentSurgicalSpecimenReader;
use App\Domains\Theatre\Infrastructure\Repository\EloquentAnaesthesiaRecordRepository;
use App\Domains\Theatre\Infrastructure\Repository\EloquentCaseEventRepository;
use App\Domains\Theatre\Infrastructure\Repository\EloquentOperatingRoomRepository;
use App\Domains\Theatre\Infrastructure\Repository\EloquentOperativeNoteRepository;
use App\Domains\Theatre\Infrastructure\Repository\EloquentRecoveryHandoffRepository;
use App\Domains\Theatre\Infrastructure\Repository\EloquentSurgicalCaseRepository;
use App\Domains\Theatre\Infrastructure\Repository\EloquentSurgicalProcedureRepository;
use App\Domains\Theatre\Infrastructure\Repository\EloquentSurgicalSpecimenRepository;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class TheatreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OperatingRoomRepository::class, EloquentOperatingRoomRepository::class);
        $this->app->bind(SurgicalCaseRepository::class, EloquentSurgicalCaseRepository::class);
        $this->app->bind(OperativeNoteRepository::class, EloquentOperativeNoteRepository::class);
        $this->app->bind(AnaesthesiaRecordRepository::class, EloquentAnaesthesiaRecordRepository::class);
        $this->app->bind(SurgicalProcedureRepository::class, EloquentSurgicalProcedureRepository::class);
        $this->app->bind(SurgicalSpecimenRepository::class, EloquentSurgicalSpecimenRepository::class);
        $this->app->bind(RecoveryHandoffRepository::class, EloquentRecoveryHandoffRepository::class);
        $this->app->bind(CaseEventRepository::class, EloquentCaseEventRepository::class);

        $this->app->bind(OperatingRoomReader::class, EloquentOperatingRoomReader::class);
        $this->app->bind(SurgicalCaseReader::class, EloquentSurgicalCaseReader::class);
        $this->app->bind(OperativeNoteReader::class, EloquentOperativeNoteReader::class);
        $this->app->bind(AnaesthesiaRecordReader::class, EloquentAnaesthesiaRecordReader::class);
        $this->app->bind(SurgicalProcedureReader::class, EloquentSurgicalProcedureReader::class);
        $this->app->bind(SurgicalSpecimenReader::class, EloquentSurgicalSpecimenReader::class);
        $this->app->bind(RecoveryHandoffReader::class, EloquentRecoveryHandoffReader::class);
        $this->app->bind(CaseEventReader::class, EloquentCaseEventReader::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        CommandBus::map([
            CreateSurgicalCase::class => CreateSurgicalCaseHandler::class,
            ScheduleSurgicalCase::class => ScheduleSurgicalCaseHandler::class,
            RescheduleSurgicalCase::class => RescheduleSurgicalCaseHandler::class,
            CreateOperatingRoom::class => CreateOperatingRoomHandler::class,
            AssignOperatingRoom::class => AssignOperatingRoomHandler::class,
            AssignTeamMember::class => AssignTeamMemberHandler::class,
            ReplaceTeamMember::class => ReplaceTeamMemberHandler::class,
            CompleteChecklistStage::class => CompleteChecklistStageHandler::class,
            StartPreOp::class => StartPreOpHandler::class,
            MarkCaseReady::class => MarkCaseReadyHandler::class,
            StartCase::class => StartCaseHandler::class,
            CompleteCase::class => CompleteCaseHandler::class,
            CancelCase::class => CancelCaseHandler::class,
            PostponeCase::class => PostponeCaseHandler::class,
            AbortCase::class => AbortCaseHandler::class,
            MarkNoShow::class => MarkNoShowHandler::class,
            CreateOperativeNote::class => CreateOperativeNoteHandler::class,
            UpdateOperativeNoteContent::class => UpdateOperativeNoteContentHandler::class,
            ReviewOperativeNote::class => ReviewOperativeNoteHandler::class,
            SignOperativeNote::class => SignOperativeNoteHandler::class,
            AmendOperativeNote::class => AmendOperativeNoteHandler::class,
            RecordAnaesthesia::class => RecordAnaesthesiaHandler::class,
            UpdateAnaesthesiaContent::class => UpdateAnaesthesiaContentHandler::class,
            SignAnaesthesiaRecord::class => SignAnaesthesiaRecordHandler::class,
            AmendAnaesthesiaRecord::class => AmendAnaesthesiaRecordHandler::class,
            RecordPlannedProcedure::class => RecordPlannedProcedureHandler::class,
            RecordSurgicalSpecimen::class => RecordSurgicalSpecimenHandler::class,
            LabelSurgicalSpecimen::class => LabelSurgicalSpecimenHandler::class,
            HandOffSurgicalSpecimen::class => HandOffSurgicalSpecimenHandler::class,
            MarkSurgicalSpecimenReceived::class => MarkSurgicalSpecimenReceivedHandler::class,
            StartProcedure::class => StartProcedureHandler::class,
            CompleteProcedure::class => CompleteProcedureHandler::class,
            AbortProcedure::class => AbortProcedureHandler::class,
            InitiateRecoveryHandoff::class => InitiateRecoveryHandoffHandler::class,
            AcknowledgeRecoveryHandoff::class => AcknowledgeRecoveryHandoffHandler::class,
            RecordCaseEvent::class => RecordCaseEventHandler::class,
        ]);

        QueryBus::map([
            GetSurgicalCase::class => GetSurgicalCaseHandler::class,
            GetOperatingRoom::class => GetOperatingRoomHandler::class,
            GetOrBoard::class => GetOrBoardHandler::class,
            GetActiveCases::class => GetActiveCasesHandler::class,
            GetOperativeNote::class => GetOperativeNoteHandler::class,
            GetOperativeNoteForCase::class => GetOperativeNoteForCaseHandler::class,
            GetAnaesthesiaRecord::class => GetAnaesthesiaRecordHandler::class,
            GetAnaesthesiaRecordForCase::class => GetAnaesthesiaRecordForCaseHandler::class,
            GetProceduresForCase::class => GetProceduresForCaseHandler::class,
            GetSurgicalSpecimen::class => GetSurgicalSpecimenHandler::class,
            GetCaseSpecimens::class => GetCaseSpecimensHandler::class,
            GetRecoveryHandoffForCase::class => GetRecoveryHandoffForCaseHandler::class,
            GetCaseEvents::class => GetCaseEventsHandler::class,
        ]);

        Gate::policy(OperatingRoom::class, OperatingRoomPolicy::class);
        Gate::policy(SurgicalCase::class, SurgicalCasePolicy::class);
        Gate::policy(OperativeNote::class, OperativeNotePolicy::class);
        Gate::policy(AnaesthesiaRecord::class, AnaesthesiaRecordPolicy::class);
        Gate::policy(SurgicalProcedure::class, SurgicalProcedurePolicy::class);
        Gate::policy(SurgicalSpecimen::class, SurgicalSpecimenPolicy::class);
        Gate::policy(RecoveryHandoff::class, RecoveryHandoffPolicy::class);
        Gate::policy(CaseEvent::class, CaseEventPolicy::class);
    }
}
