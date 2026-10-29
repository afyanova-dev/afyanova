<?php

declare(strict_types=1);

use App\Domains\Theatre\Domain\Exception\IllegalRecoveryHandoffTransition;
use App\Domains\Theatre\Domain\Exception\IllegalSurgicalProcedureTransition;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseNotEligibleForProcedure;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseNotEligibleForRecoveryHandoff;
use App\Domains\Theatre\Domain\Model\RecoveryHandoff;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\Model\SurgicalProcedure;
use App\Domains\Theatre\Domain\Service\RecoveryHandoffLifecycle;
use App\Domains\Theatre\Domain\Service\SurgicalProcedureLifecycle;

test('SurgicalProcedureLifecycle: PLANNED -> IN_PROGRESS -> COMPLETED; abort from PLANNED|IN_PROGRESS', function () {
    $l = new SurgicalProcedureLifecycle;

    $l->assertCanStart(SurgicalProcedure::STATUS_PLANNED);
    $l->assertCanComplete(SurgicalProcedure::STATUS_IN_PROGRESS);
    $l->assertCanAbort(SurgicalProcedure::STATUS_PLANNED);
    $l->assertCanAbort(SurgicalProcedure::STATUS_IN_PROGRESS);

    expect(fn () => $l->assertCanStart(SurgicalProcedure::STATUS_IN_PROGRESS))->toThrow(IllegalSurgicalProcedureTransition::class);
    expect(fn () => $l->assertCanComplete(SurgicalProcedure::STATUS_PLANNED))->toThrow(IllegalSurgicalProcedureTransition::class);
    expect(fn () => $l->assertCanAbort(SurgicalProcedure::STATUS_COMPLETED))->toThrow(IllegalSurgicalProcedureTransition::class);

    $l->assertCaseEligibleForProcedure('c', SurgicalCase::STATUS_SCHEDULED);
    $l->assertCaseEligibleForProcedure('c', SurgicalCase::STATUS_IN_PROGRESS);
    expect(fn () => $l->assertCaseEligibleForProcedure('c', SurgicalCase::STATUS_REQUESTED))
        ->toThrow(SurgicalCaseNotEligibleForProcedure::class);
});

test('RecoveryHandoffLifecycle: INITIATED -> ACKNOWLEDGED; case gate {IN_PROGRESS, COMPLETED, ABORTED}', function () {
    $l = new RecoveryHandoffLifecycle;

    $l->assertCanAcknowledge(RecoveryHandoff::STATUS_INITIATED);
    expect(fn () => $l->assertCanAcknowledge(RecoveryHandoff::STATUS_ACKNOWLEDGED))
        ->toThrow(IllegalRecoveryHandoffTransition::class);

    $l->assertCaseEligibleForHandoff('c', SurgicalCase::STATUS_COMPLETED);
    expect(fn () => $l->assertCaseEligibleForHandoff('c', SurgicalCase::STATUS_SCHEDULED))
        ->toThrow(SurgicalCaseNotEligibleForRecoveryHandoff::class);
});
