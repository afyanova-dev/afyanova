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
 * Amend an ACTIVE purchase contract in place (19-procurement-domain.md §195,
 * §248; ADR-178 D-C). ACTIVE → ACTIVE; `version` / `amendment_count` bump; one
 * append-only `pcr_contract_amendments` row per change.
 *
 * `$changes` — a non-empty list; each entry is one of:
 *   { field: 'expiry_date', value: <Y-m-d> }              — must be strictly later than the current expiry
 *   { field: 'terms',       value: <text|null> }
 *   { field: 'title',       value: <non-blank text> }
 *   { field: 'quantity_ceiling', contractLineId: <uuid>, value: <decimal|null> }
 *                                                          — lower only to ≥ that line's current committed
 *   { field: 'add_line',    line: {lineType, itemRef?, description?, agreedUnitPrice, quantityCeiling?, uom} }
 *
 * NOT `agreed_unit_price` on an existing line, NOT supplier / currency /
 * effective_date, NOT line removal — those need `RenewContract` or terminate +
 * recreate. `reason` is mandatory + ROW-ONLY.
 *
 * @param  array<int, array<string, mixed>>  $changes
 */
final readonly class AmendContract implements Auditable, AuthorizesAgainst
{
    public const FIELD_EXPIRY_DATE = 'expiry_date';

    public const FIELD_TERMS = 'terms';

    public const FIELD_TITLE = 'title';

    public const FIELD_QUANTITY_CEILING = 'quantity_ceiling';

    public const FIELD_ADD_LINE = 'add_line';

    /** @var list<array<string, mixed>> */
    public array $changes;

    /**
     * @param  array<int, array<string, mixed>>  $changes
     */
    public function __construct(
        public string $contractId,
        public string $actorId,
        array $changes,
        public string $reason,
    ) {
        if (! Str::isUuid($contractId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AmendContract ids must be UUIDs (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('AmendContract::$reason must not be blank.');
        }

        $changes = array_values($changes);
        if ($changes === []) {
            throw new InvalidArgumentException('AmendContract::$changes must not be empty.');
        }

        $allowed = [
            self::FIELD_EXPIRY_DATE, self::FIELD_TERMS, self::FIELD_TITLE,
            self::FIELD_QUANTITY_CEILING, self::FIELD_ADD_LINE,
        ];

        foreach ($changes as $i => $change) {
            $field = $change['field'] ?? null;
            if (! is_string($field) || ! in_array($field, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('AmendContract change %d has an unknown `field`.', $i + 1));
            }

            match ($field) {
                self::FIELD_EXPIRY_DATE => $this->assertValidDate($change['value'] ?? null),
                self::FIELD_TITLE => $this->assertNonBlank($change['value'] ?? null),
                self::FIELD_QUANTITY_CEILING => $this->assertCeilingChange($change, $i),
                self::FIELD_ADD_LINE => $this->assertLine($change['line'] ?? null, $i),
                default => null, // terms — text or null, nothing to validate
            };
        }

        $this->changes = $changes;
    }

    private function assertValidDate(mixed $value): void
    {
        if (! is_string($value) || strtotime($value) === false) {
            throw new InvalidArgumentException('AmendContract expiry_date change needs a valid date.');
        }
    }

    private function assertNonBlank(mixed $value): void
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('AmendContract title change needs a non-blank value.');
        }
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function assertCeilingChange(array $change, int $i): void
    {
        $ref = $change['contractLineId'] ?? null;
        if (! is_string($ref) || ! Str::isUuid($ref)) {
            throw new InvalidArgumentException(sprintf('AmendContract change %d needs a `contractLineId` UUID.', $i + 1));
        }
        $value = $change['value'] ?? null;
        if ($value !== null && (! is_numeric((string) $value) || (float) $value <= 0)) {
            throw new InvalidArgumentException(sprintf('AmendContract change %d `quantity_ceiling` must be a positive number or null.', $i + 1));
        }
    }

    /**
     * @param  mixed  $line
     */
    private function assertLine($line, int $i): void
    {
        if (! is_array($line)) {
            throw new InvalidArgumentException(sprintf('AmendContract change %d `add_line` needs a `line` object.', $i + 1));
        }
        $price = $line['agreedUnitPrice'] ?? null;
        if ($price === null || ! is_numeric((string) $price) || (float) $price < 0) {
            throw ProcurementLineSetInvalid::empty('contract line');
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->contractId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.contract_amended',
            actorId: $this->actorId,
            targetType: 'contract',
            targetId: $this->contractId,
            reason: trim($this->reason),
            metadata: [
                'change_count' => count($this->changes),
                'fields' => array_map(
                    static fn (array $c): string => (string) ($c['field'] ?? ''),
                    $this->changes,
                ),
            ],
        );
    }
}
