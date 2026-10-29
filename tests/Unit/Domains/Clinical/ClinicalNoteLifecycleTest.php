<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\Enum\ClinicalNoteStatus;
use App\Domains\Clinical\Domain\Exception\IllegalClinicalNoteTransition;
use App\Domains\Clinical\Domain\Service\ClinicalNoteLifecycle;

/*
 | PHASE 6 (C.1) unit gate — the ClinicalNoteLifecycle transition matrix
 | (12-clinical-domain.md §12; ADR-134). Pure domain logic, no I/O.
 */

beforeEach(function () {
    $this->lifecycle = new ClinicalNoteLifecycle;
});

// ── SignClinicalNote: DRAFT -> SIGNED ───────────────────────────────

test('assertCanSign allows DRAFT and rejects every other status', function (ClinicalNoteStatus $status, bool $allowed) {
    if ($allowed) {
        $this->lifecycle->assertCanSign($status);
        expect(true)->toBeTrue();

        return;
    }

    try {
        $this->lifecycle->assertCanSign($status);
        test()->fail("expected IllegalClinicalNoteTransition for {$status->value}");
    } catch (IllegalClinicalNoteTransition $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_clinical_note_transition');
    }
})->with([
    'DRAFT' => [ClinicalNoteStatus::Draft, true],
    'SIGNED (not idempotent)' => [ClinicalNoteStatus::Signed, false],
    'CANCELLED' => [ClinicalNoteStatus::Cancelled, false],
]);

// ── CancelClinicalNote: DRAFT -> CANCELLED ──────────────────────────

test('assertCanCancel allows DRAFT and rejects SIGNED / CANCELLED', function (ClinicalNoteStatus $status, bool $allowed) {
    if ($allowed) {
        $this->lifecycle->assertCanCancel($status);
        expect(true)->toBeTrue();

        return;
    }

    expect(fn () => $this->lifecycle->assertCanCancel($status))->toThrow(IllegalClinicalNoteTransition::class);
})->with([
    'DRAFT' => [ClinicalNoteStatus::Draft, true],
    'SIGNED (corrections are amendments)' => [ClinicalNoteStatus::Signed, false],
    'CANCELLED (not idempotent)' => [ClinicalNoteStatus::Cancelled, false],
]);

// ── AmendClinicalNote: SIGNED -> SIGNED ─────────────────────────────

test('assertCanAmend allows SIGNED and rejects DRAFT / CANCELLED', function (ClinicalNoteStatus $status, bool $allowed) {
    if ($allowed) {
        $this->lifecycle->assertCanAmend($status);
        expect(true)->toBeTrue();

        return;
    }

    expect(fn () => $this->lifecycle->assertCanAmend($status))->toThrow(IllegalClinicalNoteTransition::class);
})->with([
    'DRAFT (edit the draft directly)' => [ClinicalNoteStatus::Draft, false],
    'SIGNED' => [ClinicalNoteStatus::Signed, true],
    'CANCELLED (nothing to amend)' => [ClinicalNoteStatus::Cancelled, false],
]);

test('the lifecycle service is final and stateless', function () {
    expect((new ReflectionClass(ClinicalNoteLifecycle::class))->isFinal())->toBeTrue()
        ->and((new ReflectionClass(ClinicalNoteLifecycle::class))->getProperties())->toBe([]);
});
