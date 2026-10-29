<?php

declare(strict_types=1);

use App\Platform\Integration\Application\Command\IngestFileImportCommand;
use App\Platform\Integration\Application\Command\RecordImportRecordOutcomeCommand;
use App\Platform\Integration\Application\Query\GetFileImportReconciliationQuery;
use App\Platform\Integration\Contracts\Dto\FileImportIngestDto;
use App\Platform\Integration\Contracts\Dto\FileImportReconciliationDto;
use App\Platform\Integration\Domain\Enum\FileImportStatus;
use App\Platform\Integration\Domain\Model\FileImport;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;

require_once __DIR__.'/../../../../tests/Support/integration.php';

beforeEach(function () {
    $this->fixture = integrationFixture();
});

test('ingests batch file import and reconciles cleanly when all records are committed', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId, 'RITA Birth Registry', 'rita-births');

        $records = [
            ['ref' => 'RITA-001', 'name' => 'Juma Ali', 'dob' => '2026-01-01'],
            ['ref' => 'RITA-002', 'name' => 'Amina Bakari', 'dob' => '2026-01-02'],
            ['ref' => 'RITA-003', 'name' => 'Neema Charles', 'dob' => '2026-01-03'],
        ];

        $dto = new FileImportIngestDto(
            tenantId: $tenantId,
            connectorId: $connector->id,
            filename: 'births_2026_01.csv',
            byteSize: 1024,
            records: $records,
            externalRef: 'BATCH-JAN-2026',
        );

        /** @var FileImport $import */
        $import = app(CommandBus::class)->dispatch(new IngestFileImportCommand($dto));

        expect($import->id)->not->toBeEmpty()
            ->and($import->record_count)->toBe(3)
            ->and($import->processed_count)->toBe(0)
            ->and($import->status)->toBe(FileImportStatus::RECEIVED);

        // Record outcome for record 0
        app(CommandBus::class)->dispatch(new RecordImportRecordOutcomeCommand(
            fileImportId: $import->id,
            recordIndex: 0,
            committed: true,
            reason: 'Patient created with MRN P-001',
        ));

        // Record outcome for record 1
        app(CommandBus::class)->dispatch(new RecordImportRecordOutcomeCommand(
            fileImportId: $import->id,
            recordIndex: 1,
            committed: true,
            reason: 'Patient created with MRN P-002',
        ));

        // Record outcome for record 2
        /** @var FileImport $completedImport */
        $completedImport = app(CommandBus::class)->dispatch(new RecordImportRecordOutcomeCommand(
            fileImportId: $import->id,
            recordIndex: 2,
            committed: true,
            reason: 'Patient created with MRN P-003',
        ));

        expect($completedImport->status)->toBe(FileImportStatus::RECONCILED)
            ->and($completedImport->processed_count)->toBe(3)
            ->and($completedImport->committed_count)->toBe(3)
            ->and($completedImport->rejected_count)->toBe(0);

        // Query reconciliation view
        /** @var FileImportReconciliationDto $reconciliation */
        $reconciliation = app(QueryBus::class)->dispatch(new GetFileImportReconciliationQuery($import->id));
        expect($reconciliation->status)->toBe(FileImportStatus::RECONCILED)
            ->and($reconciliation->committedCount)->toBe(3)
            ->and($reconciliation->rejectedCount)->toBe(0);
    });
});

test('settles file import status to PARTIAL when one or more records are rejected', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId);

        $records = [
            ['ref' => 'REC-1', 'value' => 10],
            ['ref' => 'REC-2', 'value' => 20],
        ];

        $dto = new FileImportIngestDto(
            tenantId: $tenantId,
            connectorId: $connector->id,
            filename: 'data.csv',
            byteSize: 512,
            records: $records,
        );

        /** @var FileImport $import */
        $import = app(CommandBus::class)->dispatch(new IngestFileImportCommand($dto));

        // Record 0 succeeds
        app(CommandBus::class)->dispatch(new RecordImportRecordOutcomeCommand(
            fileImportId: $import->id,
            recordIndex: 0,
            committed: true,
        ));

        // Record 1 rejected by domain (e.g. invalid syntax or validation error)
        /** @var FileImport $partialImport */
        $partialImport = app(CommandBus::class)->dispatch(new RecordImportRecordOutcomeCommand(
            fileImportId: $import->id,
            recordIndex: 1,
            committed: false,
            reason: 'Invalid national ID format',
        ));

        expect($partialImport->status)->toBe(FileImportStatus::PARTIAL)
            ->and($partialImport->processed_count)->toBe(2)
            ->and($partialImport->committed_count)->toBe(1)
            ->and($partialImport->rejected_count)->toBe(1);
    });
});
