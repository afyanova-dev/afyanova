<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Exception\ProcurementLineSetInvalid;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Create a Request for Quotation as DRAFT (19-procurement-domain.md §29–§31;
 * ADR-173 D-D). `$rfqId` is client-minted (UUIDv7). `>= 1` line, `>= 1`
 * invitation; STOCK lines validated + snapshotted via `InventoryApi::getItem`
 * (`ProcurementContextResolver`); every invited supplier must be ACTIVE.
 * `requisitionId` OPTIONAL (no auto-copy of lines — the ADR-171 PO precedent);
 * when set the requisition must be APPROVED + same facility. `facility_id` from
 * the command, validated against the actor's scope (§42 / Inv 42).
 *
 * @param  array<int, array<string, mixed>>  $lines
 * @param  list<string>  $invitations  supplier ids
 */
final readonly class CreateRfq implements Auditable, AuthorizesAgainst
{
    /** @var list<array<string, mixed>> */
    public array $lines;

    /** @var list<string> */
    public array $invitations;

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  list<string>  $invitations
     */
    public function __construct(
        public string $rfqId,
        public string $actorId,
        public string $facilityId,
        public string $title,
        array $lines,
        array $invitations,
        public ?string $requisitionId = null,
        public ?string $reason = null,
        public ?string $requiredDate = null,
        public ?string $closingDate = null,
        public ?string $terms = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['rfqId' => $rfqId, 'actorId' => $actorId, 'facilityId' => $facilityId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CreateRfq::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($requisitionId !== null && ! Str::isUuid($requisitionId)) {
            throw new InvalidArgumentException('CreateRfq::$requisitionId must be a UUID or null (IC §3).');
        }

        if (trim($title) === '') {
            throw new InvalidArgumentException('CreateRfq::$title must not be blank.');
        }

        $lines = array_values($lines);
        if ($lines === []) {
            throw ProcurementLineSetInvalid::empty('RFQ');
        }

        $invitations = array_values(array_unique(array_map(
            static fn ($s): string => (string) $s,
            $invitations,
        )));
        if ($invitations === []) {
            throw new InvalidArgumentException('CreateRfq::$invitations must name at least one supplier (§30 / §33).');
        }
        foreach ($invitations as $supplierId) {
            if (! Str::isUuid($supplierId)) {
                throw new InvalidArgumentException('CreateRfq::$invitations must be supplier UUIDs (IC §3).');
            }
        }

        $this->lines = $lines;
        $this->invitations = $invitations;
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.rfq_created',
            actorId: $this->actorId,
            targetType: 'rfq',
            targetId: $this->rfqId,
            metadata: [
                'facility_id' => $this->facilityId,
                'line_count' => count($this->lines),
                'invitation_count' => count($this->invitations),
            ],
        );
    }
}
