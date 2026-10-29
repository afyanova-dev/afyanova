<?php

declare(strict_types=1);

use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Repository\TicketCounterRepository;
use App\Shared\Tenancy\TenantContextScope;

require_once __DIR__.'/../../../../tests/Support/queueing.php';

it('maintains independent ticket counters across different queues and facilities', function (): void {
    $fx = queueingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $counterRepo = app(TicketCounterRepository::class);

    $date = '2026-09-11';

    // Queue 1: TRIAGE
    $q1_seq1 = TenantContextScope::run($tenantId, $facilityId, fn () => $counterRepo->nextNumber($facilityId, 'TRIAGE', $date));
    $q1_seq2 = TenantContextScope::run($tenantId, $facilityId, fn () => $counterRepo->nextNumber($facilityId, 'TRIAGE', $date));
    $q1_seq3 = TenantContextScope::run($tenantId, $facilityId, fn () => $counterRepo->nextNumber($facilityId, 'TRIAGE', $date));

    expect($q1_seq1)->toBe(1)
        ->and($q1_seq2)->toBe(2)
        ->and($q1_seq3)->toBe(3);

    // Queue 2: PHARMACY in same facility starts at 1
    $q2_seq1 = TenantContextScope::run($tenantId, $facilityId, fn () => $counterRepo->nextNumber($facilityId, 'PHARMACY', $date));
    expect($q2_seq1)->toBe(1);

    // Tomorrow starts at 1
    $tomorrow = '2026-09-12';
    $tomorrow_seq = TenantContextScope::run($tenantId, $facilityId, fn () => $counterRepo->nextNumber($facilityId, 'TRIAGE', $tomorrow));
    expect($tomorrow_seq)->toBe(1);
});
