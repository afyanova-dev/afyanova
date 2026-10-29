<?php

declare(strict_types=1);

use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Exception\IllegalEncounterTransition;
use App\Domains\Encounter\Domain\Service\EncounterLifecycle;

/*
 | PHASE 5 (E.1 + E.2) unit gate — the EncounterLifecycle transition rules
 | (ADR-121). E.2 adds the `PLANNED → ARRIVED` guard.
 */

test('a walk-in check-in produces ARRIVED (the PHASE 5 default)', function () {
    expect((new EncounterLifecycle)->initialStatus(planned: false))->toBe(EncounterStatus::Arrived);
});

test('an explicit planned intent produces PLANNED', function () {
    expect((new EncounterLifecycle)->initialStatus(planned: true))->toBe(EncounterStatus::Planned);
});

test('assertCanRecordArrival allows PLANNED and only PLANNED (ADR-121)', function () {
    (new EncounterLifecycle)->assertCanRecordArrival(EncounterStatus::Planned); // must not throw

    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Planned) {
            continue;
        }
        expect(fn () => (new EncounterLifecycle)->assertCanRecordArrival($status))
            ->toThrow(IllegalEncounterTransition::class);
    }
});

test('assertCanRecordArrival rejects an already-ARRIVED encounter — arrival is not idempotent', function () {
    try {
        (new EncounterLifecycle)->assertCanRecordArrival(EncounterStatus::Arrived);
        $this->fail('expected IllegalEncounterTransition');
    } catch (IllegalEncounterTransition $e) {
        expect($e->httpStatus())->toBe(409)
            ->and($e->errorCode())->toBe('illegal_encounter_transition');
    }
});

test('assertCanStart allows ARRIVED and only ARRIVED (ADR-121)', function () {
    (new EncounterLifecycle)->assertCanStart(EncounterStatus::Arrived); // must not throw

    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Arrived) {
            continue;
        }
        expect(fn () => (new EncounterLifecycle)->assertCanStart($status))
            ->toThrow(IllegalEncounterTransition::class);
    }
});

test('assertCanStart rejects an already-ACTIVE encounter — starting is not idempotent — with 409 / illegal_encounter_transition', function () {
    try {
        (new EncounterLifecycle)->assertCanStart(EncounterStatus::Active);
        $this->fail('expected IllegalEncounterTransition');
    } catch (IllegalEncounterTransition $e) {
        expect($e->httpStatus())->toBe(409)
            ->and($e->errorCode())->toBe('illegal_encounter_transition');
    }
});

// ── E.4 — assertCanAdmit (ADR-108 / ADR-120 / ADR-121) ───────────────

test('assertCanAdmit allows ACTIVE + OUTPATIENT and ACTIVE + EMERGENCY', function () {
    expect(fn () => (new EncounterLifecycle)->assertCanAdmit(EncounterStatus::Active, EncounterType::Outpatient))
        ->not->toThrow(IllegalEncounterTransition::class);
    expect(fn () => (new EncounterLifecycle)->assertCanAdmit(EncounterStatus::Active, EncounterType::Emergency))
        ->not->toThrow(IllegalEncounterTransition::class);
});

test('assertCanAdmit rejects every non-ACTIVE source status', function () {
    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Active) {
            continue;
        }
        expect(fn () => (new EncounterLifecycle)->assertCanAdmit($status, EncounterType::Outpatient))
            ->toThrow(IllegalEncounterTransition::class);
    }
});

test('assertCanAdmit rejects every non-admissible type when status is ACTIVE (incl. INPATIENT — not idempotent)', function () {
    foreach (EncounterType::cases() as $type) {
        if ($type === EncounterType::Outpatient || $type === EncounterType::Emergency) {
            continue;
        }
        expect(fn () => (new EncounterLifecycle)->assertCanAdmit(EncounterStatus::Active, $type))
            ->toThrow(IllegalEncounterTransition::class);
    }
});

test('assertCanAdmit rejections are 409 / illegal_encounter_transition (both the status and the type path)', function () {
    try {
        (new EncounterLifecycle)->assertCanAdmit(EncounterStatus::Completed, EncounterType::Outpatient);
        $this->fail('expected IllegalEncounterTransition (status)');
    } catch (IllegalEncounterTransition $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
    }

    try {
        (new EncounterLifecycle)->assertCanAdmit(EncounterStatus::Active, EncounterType::Inpatient);
        $this->fail('expected IllegalEncounterTransition (type)');
    } catch (IllegalEncounterTransition $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
    }
});

// ── E.5 — assertCanChangeDisposition (ADR-121 / ADR-130 A) ───────────

test('assertCanChangeDisposition allows ACTIVE + each of the six settable dispositions', function () {
    $settable = [
        EncounterDisposition::Discharged,
        EncounterDisposition::Referred,
        EncounterDisposition::Left,
        EncounterDisposition::FollowUp,
        EncounterDisposition::Deceased,
        EncounterDisposition::Other,
    ];

    foreach ($settable as $disposition) {
        expect(fn () => (new EncounterLifecycle)->assertCanChangeDisposition(EncounterStatus::Active, $disposition))
            ->not->toThrow(IllegalEncounterTransition::class);
    }
});

test('assertCanChangeDisposition rejects every non-ACTIVE source status', function () {
    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Active) {
            continue;
        }
        expect(fn () => (new EncounterLifecycle)->assertCanChangeDisposition($status, EncounterDisposition::Discharged))
            ->toThrow(IllegalEncounterTransition::class);
    }
});

test('assertCanChangeDisposition rejects ADMITTED and TRANSFERRED even when ACTIVE (owned by AdmitEncounter / TransferEncounter)', function () {
    expect(fn () => (new EncounterLifecycle)->assertCanChangeDisposition(EncounterStatus::Active, EncounterDisposition::Admitted))
        ->toThrow(IllegalEncounterTransition::class);
    expect(fn () => (new EncounterLifecycle)->assertCanChangeDisposition(EncounterStatus::Active, EncounterDisposition::Transferred))
        ->toThrow(IllegalEncounterTransition::class);
});

test('assertCanChangeDisposition rejections are 409 / illegal_encounter_transition (status path and value path)', function () {
    try {
        (new EncounterLifecycle)->assertCanChangeDisposition(EncounterStatus::Completed, EncounterDisposition::Discharged);
        $this->fail('expected IllegalEncounterTransition (status)');
    } catch (IllegalEncounterTransition $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
    }

    try {
        (new EncounterLifecycle)->assertCanChangeDisposition(EncounterStatus::Active, EncounterDisposition::Admitted);
        $this->fail('expected IllegalEncounterTransition (value)');
    } catch (IllegalEncounterTransition $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
    }
});

// ── E.6 — assertCanAssignLocation (ADR-121 / ADR-130 B) ─────────────

test('assertCanAssignLocation allows ARRIVED and ACTIVE and only those', function () {
    expect(fn () => (new EncounterLifecycle)->assertCanAssignLocation(EncounterStatus::Arrived))
        ->not->toThrow(IllegalEncounterTransition::class);
    expect(fn () => (new EncounterLifecycle)->assertCanAssignLocation(EncounterStatus::Active))
        ->not->toThrow(IllegalEncounterTransition::class);

    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Arrived || $status === EncounterStatus::Active) {
            continue;
        }
        expect(fn () => (new EncounterLifecycle)->assertCanAssignLocation($status))
            ->toThrow(IllegalEncounterTransition::class);
    }
});

test('assertCanAssignLocation rejections are 409 / illegal_encounter_transition', function () {
    foreach ([EncounterStatus::Planned, EncounterStatus::Completed, EncounterStatus::Cancelled] as $status) {
        try {
            (new EncounterLifecycle)->assertCanAssignLocation($status);
            $this->fail("expected IllegalEncounterTransition for {$status->value}");
        } catch (IllegalEncounterTransition $e) {
            expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
        }
    }
});

// ── E.7 — assertCanComplete (ADR-121 / ADR-130 A) ──────────────────

test('assertCanComplete allows ACTIVE + every non-null disposition (incl. ADMITTED)', function () {
    foreach (EncounterDisposition::cases() as $disposition) {
        expect(fn () => (new EncounterLifecycle)->assertCanComplete(EncounterStatus::Active, $disposition))
            ->not->toThrow(IllegalEncounterTransition::class);
    }
});

test('assertCanComplete rejects ACTIVE + null disposition (409 / illegal_encounter_transition)', function () {
    try {
        (new EncounterLifecycle)->assertCanComplete(EncounterStatus::Active, null);
        $this->fail('expected IllegalEncounterTransition');
    } catch (IllegalEncounterTransition $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
    }
});

test('assertCanComplete rejects every non-ACTIVE source status (409 / illegal_encounter_transition)', function () {
    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Active) {
            continue;
        }
        try {
            (new EncounterLifecycle)->assertCanComplete($status, EncounterDisposition::Discharged);
            $this->fail("expected IllegalEncounterTransition for {$status->value}");
        } catch (IllegalEncounterTransition $e) {
            expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
        }
    }
});

// ── E.8 — assertCanReopen (ADR-121 / ADR-130 C, Rulings E8-R1..E8-R8) ────────

test('assertCanReopen allows COMPLETED and only COMPLETED (ADR-121)', function () {
    expect(fn () => (new EncounterLifecycle)->assertCanReopen(EncounterStatus::Completed))
        ->not->toThrow(IllegalEncounterTransition::class);

    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Completed) {
            continue;
        }
        expect(fn () => (new EncounterLifecycle)->assertCanReopen($status))
            ->toThrow(IllegalEncounterTransition::class);
    }
});

test('assertCanReopen rejects every non-COMPLETED source status with 409 / illegal_encounter_transition (incl. ACTIVE — not idempotent)', function () {
    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Completed) {
            continue;
        }
        try {
            (new EncounterLifecycle)->assertCanReopen($status);
            $this->fail("expected IllegalEncounterTransition for {$status->value}");
        } catch (IllegalEncounterTransition $e) {
            expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
        }
    }
});

// ── E.9 — assertCanCancel (ADR-121 / ADR-130 A/D) ────────────────────────────

test('assertCanCancel allows PLANNED and ARRIVED, and only those two (ADR-121)', function () {
    expect(fn () => (new EncounterLifecycle)->assertCanCancel(EncounterStatus::Planned))
        ->not->toThrow(IllegalEncounterTransition::class);

    expect(fn () => (new EncounterLifecycle)->assertCanCancel(EncounterStatus::Arrived))
        ->not->toThrow(IllegalEncounterTransition::class);

    foreach (EncounterStatus::cases() as $status) {
        if (in_array($status, [EncounterStatus::Planned, EncounterStatus::Arrived], true)) {
            continue;
        }
        expect(fn () => (new EncounterLifecycle)->assertCanCancel($status))
            ->toThrow(IllegalEncounterTransition::class);
    }
});

test('assertCanCancel rejects every non-PLANNED/non-ARRIVED source status with 409 / illegal_encounter_transition', function () {
    foreach (EncounterStatus::cases() as $status) {
        if (in_array($status, [EncounterStatus::Planned, EncounterStatus::Arrived], true)) {
            continue;
        }
        try {
            (new EncounterLifecycle)->assertCanCancel($status);
            $this->fail("expected IllegalEncounterTransition for {$status->value}");
        } catch (IllegalEncounterTransition $e) {
            expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
        }
    }
});

test('assertCanAbandon returns LEFT_WITHOUT_BEING_SEEN for ARRIVED and ABANDONED for ACTIVE (ADR-121)', function () {
    $lifecycle = new EncounterLifecycle;

    expect($lifecycle->assertCanAbandon(EncounterStatus::Arrived))->toBe(EncounterStatus::LeftWithoutBeingSeen)
        ->and($lifecycle->assertCanAbandon(EncounterStatus::Active))->toBe(EncounterStatus::Abandoned);
});

test('assertCanAbandon rejects every non-ARRIVED/non-ACTIVE source status with 409 / illegal_encounter_transition', function () {
    $lifecycle = new EncounterLifecycle;

    foreach (EncounterStatus::cases() as $status) {
        if (in_array($status, [EncounterStatus::Arrived, EncounterStatus::Active], true)) {
            continue;
        }
        try {
            $lifecycle->assertCanAbandon($status);
            $this->fail("expected IllegalEncounterTransition for {$status->value}");
        } catch (IllegalEncounterTransition $e) {
            expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
        }
    }
});

// ── E.11 — assertCanTransfer (ADR-121 / ADR-130 A/C/E) ───────────────────────

test('assertCanTransfer allows ACTIVE and only ACTIVE (ADR-121)', function () {
    expect(fn () => (new EncounterLifecycle)->assertCanTransfer(EncounterStatus::Active))
        ->not->toThrow(IllegalEncounterTransition::class);

    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Active) {
            continue;
        }
        expect(fn () => (new EncounterLifecycle)->assertCanTransfer($status))
            ->toThrow(IllegalEncounterTransition::class);
    }
});

test('assertCanTransfer rejects every non-ACTIVE source status with 409 / illegal_encounter_transition (incl. ARRIVED and TRANSFERRED — not idempotent)', function () {
    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Active) {
            continue;
        }
        try {
            (new EncounterLifecycle)->assertCanTransfer($status);
            $this->fail("expected IllegalEncounterTransition for {$status->value}");
        } catch (IllegalEncounterTransition $e) {
            expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
        }
    }
});

test('EncounterStatus has exactly the nine ADR-121 cases', function () {
    expect(array_map(fn (EncounterStatus $s): string => $s->value, EncounterStatus::cases()))
        ->toEqualCanonicalizing([
            'PLANNED', 'ARRIVED', 'ACTIVE', 'COMPLETED',
            'CANCELLED', 'LEFT_WITHOUT_BEING_SEEN', 'ABANDONED', 'TRANSFERRED', 'REFERRED',
        ]);
});

test('EncounterType capability gating maps exactly the four ADR-120 types', function () {
    $gated = [];
    foreach (EncounterType::cases() as $type) {
        $capability = $type->requiredCapability();
        if ($capability !== null) {
            $gated[$type->value] = $capability;
        }
    }

    expect($gated)->toBe([
        'OUTPATIENT' => 'OUTPATIENT_CARE',
        'EMERGENCY' => 'EMERGENCY_CARE',
        'INPATIENT' => 'INPATIENT_CARE',
        'MATERNITY' => 'MATERNITY',
    ]);

    // the other seven are not capability-gated in PHASE 5
    foreach (['DIAGNOSTIC', 'PROCEDURE', 'DAY_CARE', 'FOLLOW_UP', 'TELEHEALTH', 'REFERRAL', 'OTHER'] as $ungated) {
        expect(EncounterType::from($ungated)->requiredCapability())->toBeNull();
    }
});

test('EncounterType has exactly the eleven ADR-120 cases', function () {
    expect(EncounterType::cases())->toHaveCount(11);
});
