<?php

declare(strict_types=1);

use App\Platform\Reporting\Application\Command\BuildStatutoryReturnCommand;
use App\Platform\Reporting\Application\Command\RecordReturnAcknowledgementCommand;
use App\Platform\Reporting\Application\Command\ResubmitStatutoryReturnCommand;
use App\Platform\Reporting\Application\Command\ReviewStatutoryReturnCommand;
use App\Platform\Reporting\Application\Command\SubmitStatutoryReturnCommand;
use App\Platform\Reporting\Application\Query\GetStatutoryReturnQuery;
use App\Platform\Reporting\Domain\ValueObject\StatutoryFramework;
use App\Platform\Reporting\Domain\ValueObject\StatutoryReturnStatus;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../../../tests/Support/reporting.php';

it('manages full statutory return lifecycle and immutable supersession chain per Invariant 7.8', function (): void {
    $fx = reportingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $commandBus = app(CommandBus::class);
    $queryBus = app(QueryBus::class);

    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus, $queryBus, $tenantId, $facilityId, $userId): void {
        // 1. Build Statutory Return (Draft/Generated with lines)
        $lines = [
            ['data_element_code' => 'MTUHA_105_ANC_1ST', 'category_option_combo' => 'default', 'value' => 84.0],
            ['data_element_code' => 'MTUHA_105_DELIVERIES', 'category_option_combo' => 'default', 'value' => 31.0],
        ];

        $return = $commandBus->dispatch(new BuildStatutoryReturnCommand(
            tenantId: $tenantId,
            framework: StatutoryFramework::HMIS_MTUHA,
            periodType: 'MONTHLY',
            periodKey: '2026-11',
            reportingUnitCode: 'RU-DAR-01',
            facilityId: $facilityId,
            generatedBy: $userId,
            lines: $lines,
        ));

        expect($return->status)->toBe(StatutoryReturnStatus::GENERATED)
            ->and($return->return_number)->toStartWith('RET-')
            ->and($return->submitted_dataset)->not->toBeNull()
            ->and($return->submitted_dataset['lines'])->toHaveCount(2);

        // 2. Review
        $reviewed = $commandBus->dispatch(new ReviewStatutoryReturnCommand(
            tenantId: $tenantId,
            returnId: (string) $return->id,
            reviewedBy: $userId,
        ));
        expect($reviewed->status)->toBe(StatutoryReturnStatus::REVIEWED)
            ->and($reviewed->reviewed_by)->toBe($userId);

        // 3. Submit
        $submitted = $commandBus->dispatch(new SubmitStatutoryReturnCommand(
            tenantId: $tenantId,
            returnId: (string) $return->id,
            submittedBy: $userId,
        ));
        expect($submitted->status)->toBe(StatutoryReturnStatus::SUBMITTED)
            ->and($submitted->submitted_at)->not->toBeNull();

        // 4. Acknowledge from external MOH/DHIS2
        $acked = $commandBus->dispatch(new RecordReturnAcknowledgementCommand(
            tenantId: $tenantId,
            returnId: (string) $return->id,
            acknowledgementRef: 'MOH-ACK-998822',
            receiptPayload: ['status' => 'ACCEPTED', 'timestamp' => '2026-12-01T10:00:00Z'],
        ));
        expect($acked->status)->toBe(StatutoryReturnStatus::ACKNOWLEDGED)
            ->and($acked->acknowledged_at)->not->toBeNull();

        // Capture frozen dataset before correction
        $originalFrozenDataset = $acked->submitted_dataset;

        // 5. Source correction requires resubmission (Invariant §7.8: produces a NEW return, old becomes SUPERSEDED)
        $correctedLines = [
            ['data_element_code' => 'MTUHA_105_ANC_1ST', 'category_option_combo' => 'default', 'value' => 86.0], // corrected from 84 to 86
            ['data_element_code' => 'MTUHA_105_DELIVERIES', 'category_option_combo' => 'default', 'value' => 31.0],
        ];

        $supersedingReturn = $commandBus->dispatch(new ResubmitStatutoryReturnCommand(
            tenantId: $tenantId,
            originalReturnId: (string) $return->id,
            lines: $correctedLines,
            submittedBy: $userId,
        ));

        expect($supersedingReturn->status)->toBe(StatutoryReturnStatus::GENERATED)
            ->and($supersedingReturn->supersedes_return_id)->toBe($return->id)
            ->and($supersedingReturn->return_number)->not->toBe($return->return_number);

        // Reload original return to assert it was superseded without mutating its frozen snapshot
        $reloadedOriginal = $queryBus->dispatch(new GetStatutoryReturnQuery(tenantId: $tenantId, returnId: (string) $return->id));
        expect($reloadedOriginal->status)->toBe(StatutoryReturnStatus::SUPERSEDED)
            ->and($reloadedOriginal->superseded_by_return_id)->toBe($supersedingReturn->id)
            ->and($reloadedOriginal->submitted_dataset)->toEqual($originalFrozenDataset); // Invariant 7.8 verified: snapshot untouched!

        // 6. Verify transactional outbox events were recorded
        expect(DB::table('shared_outbox_events')
            ->where('tenant_id', $tenantId)
            ->where('event_name', 'reporting.StatutoryReturnSubmitted')
            ->exists())->toBeTrue()
            ->and(DB::table('shared_outbox_events')
                ->where('tenant_id', $tenantId)
                ->where('event_name', 'reporting.StatutoryReturnAcknowledged')
                ->exists())->toBeTrue();
    });
});
