<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\Enum\ClinicalProcedureStatus;
use App\Domains\Clinical\Domain\Exception\IllegalProcedureTransition;
use App\Domains\Clinical\Domain\Service\ClinicalProcedureLifecycle;

/*
 | PHASE 6 (CP.1) unit gate — ClinicalProcedureLifecycle (ADR-137, ADR-146 D-F).
 | Pure domain logic, no I/O.
 */

beforeEach(function () {
    $this->lifecycle = new ClinicalProcedureLifecycle;
});

test('AuthorizeProcedure is legal only from REQUESTED', function () {
    $this->lifecycle->assertCanAuthorize(ClinicalProcedureStatus::Requested);

    foreach (ClinicalProcedureStatus::cases() as $status) {
        if ($status === ClinicalProcedureStatus::Requested) {
            continue;
        }
        expect(fn () => $this->lifecycle->assertCanAuthorize($status))->toThrow(IllegalProcedureTransition::class);
    }
});

test('MarkProcedureReady is legal only from AUTHORIZED AND only when the definition requires a readiness check', function () {
    $this->lifecycle->assertCanMarkReady(ClinicalProcedureStatus::Authorized, true);

    expect(fn () => $this->lifecycle->assertCanMarkReady(ClinicalProcedureStatus::Authorized, false))
        ->toThrow(IllegalProcedureTransition::class, 'does not require a readiness check');

    expect(fn () => $this->lifecycle->assertCanMarkReady(ClinicalProcedureStatus::Requested, true))
        ->toThrow(IllegalProcedureTransition::class);
});

test('StartProcedure: legal from READY unconditionally, from AUTHORIZED only when readiness is NOT required', function () {
    $this->lifecycle->assertCanStart(ClinicalProcedureStatus::Ready, true);
    $this->lifecycle->assertCanStart(ClinicalProcedureStatus::Ready, false);
    $this->lifecycle->assertCanStart(ClinicalProcedureStatus::Authorized, false);

    expect(fn () => $this->lifecycle->assertCanStart(ClinicalProcedureStatus::Authorized, true))
        ->toThrow(IllegalProcedureTransition::class);
    expect(fn () => $this->lifecycle->assertCanStart(ClinicalProcedureStatus::Requested, false))
        ->toThrow(IllegalProcedureTransition::class);
});

test('CompleteProcedure is legal only from IN_PROGRESS', function () {
    $this->lifecycle->assertCanComplete(ClinicalProcedureStatus::InProgress);

    foreach (ClinicalProcedureStatus::cases() as $status) {
        if ($status === ClinicalProcedureStatus::InProgress) {
            continue;
        }
        expect(fn () => $this->lifecycle->assertCanComplete($status))->toThrow(IllegalProcedureTransition::class);
    }
});

test('CancelProcedure is legal only from {REQUESTED, AUTHORIZED, READY}', function () {
    foreach ([ClinicalProcedureStatus::Requested, ClinicalProcedureStatus::Authorized, ClinicalProcedureStatus::Ready] as $status) {
        $this->lifecycle->assertCanCancel($status);
    }

    foreach ([ClinicalProcedureStatus::InProgress, ClinicalProcedureStatus::Completed, ClinicalProcedureStatus::Aborted, ClinicalProcedureStatus::Cancelled, ClinicalProcedureStatus::Declined] as $status) {
        expect(fn () => $this->lifecycle->assertCanCancel($status))->toThrow(IllegalProcedureTransition::class);
    }
});

test('AbortProcedure is legal only from IN_PROGRESS (distinct from CancelProcedure)', function () {
    $this->lifecycle->assertCanAbort(ClinicalProcedureStatus::InProgress);

    expect(fn () => $this->lifecycle->assertCanAbort(ClinicalProcedureStatus::Requested))->toThrow(IllegalProcedureTransition::class);
});

test('DeclineProcedure is legal only from {AUTHORIZED, READY} — the ADR-137 refinement (ADR-146 D-F)', function () {
    $this->lifecycle->assertCanDecline(ClinicalProcedureStatus::Authorized);
    $this->lifecycle->assertCanDecline(ClinicalProcedureStatus::Ready);

    expect(fn () => $this->lifecycle->assertCanDecline(ClinicalProcedureStatus::Requested))->toThrow(IllegalProcedureTransition::class);
});

test('RecordProcedureOutcome is legal only while IN_PROGRESS — post-completion use AmendProcedure (S3/O-2)', function () {
    $this->lifecycle->assertCanRecordOutcome(ClinicalProcedureStatus::InProgress);

    expect(fn () => $this->lifecycle->assertCanRecordOutcome(ClinicalProcedureStatus::Completed))
        ->toThrow(IllegalProcedureTransition::class);
});

test('AddProcedureNote is legal in {IN_PROGRESS, COMPLETED, ABORTED}', function () {
    foreach ([ClinicalProcedureStatus::InProgress, ClinicalProcedureStatus::Completed, ClinicalProcedureStatus::Aborted] as $status) {
        $this->lifecycle->assertCanAddNote($status);
    }

    foreach ([ClinicalProcedureStatus::Requested, ClinicalProcedureStatus::Authorized, ClinicalProcedureStatus::Ready, ClinicalProcedureStatus::Cancelled, ClinicalProcedureStatus::Declined] as $status) {
        expect(fn () => $this->lifecycle->assertCanAddNote($status))->toThrow(IllegalProcedureTransition::class);
    }
});

test('RecordProcedureMaterials is legal "during or after" — {IN_PROGRESS, COMPLETED} (13 §52)', function () {
    $this->lifecycle->assertCanRecordMaterials(ClinicalProcedureStatus::InProgress);
    $this->lifecycle->assertCanRecordMaterials(ClinicalProcedureStatus::Completed);

    expect(fn () => $this->lifecycle->assertCanRecordMaterials(ClinicalProcedureStatus::Aborted))
        ->toThrow(IllegalProcedureTransition::class);
});

test('AmendProcedure is legal only in {COMPLETED, ABORTED}', function () {
    $this->lifecycle->assertCanAmend(ClinicalProcedureStatus::Completed);
    $this->lifecycle->assertCanAmend(ClinicalProcedureStatus::Aborted);

    expect(fn () => $this->lifecycle->assertCanAmend(ClinicalProcedureStatus::InProgress))
        ->toThrow(IllegalProcedureTransition::class);
});
