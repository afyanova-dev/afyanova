<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record one pre-close revision of a quotation (19-procurement-domain.md §233;
 * ADR-173 D-D). Legal only while the quotation is RECEIVED AND the RFQ is
 * ISSUED. ONE structured field per revision; appends a `pcr_quotation_revisions`
 * row and bumps `revision_count`. `reason` is mandatory + ROW-ONLY.
 *
 *   LINE fields   (quotationLineId required): unit_price | offered_qty |
 *                 tax_amount | discount_amount
 *   HEADER fields (quotationLineId MUST be null): lead_time_days | validity_date
 *                 | payment_terms | delivery_terms
 */
final readonly class ReviseQuotation implements Auditable, AuthorizesAgainst
{
    private const LINE_FIELDS = ['unit_price', 'offered_qty', 'tax_amount', 'discount_amount'];

    private const HEADER_FIELDS = ['lead_time_days', 'validity_date', 'payment_terms', 'delivery_terms'];

    public function __construct(
        public string $quotationId,
        public string $actorId,
        public string $field,
        public string $reason,
        public ?string $newValue = null,
        public ?string $quotationLineId = null,
    ) {
        if (! Str::isUuid($quotationId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ReviseQuotation ids must be UUIDs (IC §3).');
        }

        if (! in_array($field, [...self::LINE_FIELDS, ...self::HEADER_FIELDS], true)) {
            throw new InvalidArgumentException(sprintf(
                'ReviseQuotation::$field must be one of %s.',
                implode(' | ', [...self::LINE_FIELDS, ...self::HEADER_FIELDS]),
            ));
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('ReviseQuotation::$reason must not be blank (§233).');
        }

        if ($this->isLineField()) {
            if ($quotationLineId === null || ! Str::isUuid($quotationLineId)) {
                throw new InvalidArgumentException("ReviseQuotation::\$quotationLineId (a UUID) is required for a {$field} revision.");
            }
        } elseif ($quotationLineId !== null) {
            throw new InvalidArgumentException("ReviseQuotation::\$quotationLineId must be null for a {$field} revision.");
        }

        $this->assertNewValueShape();
    }

    public function isLineField(): bool
    {
        return in_array($this->field, self::LINE_FIELDS, true);
    }

    public function policyAbility(): string
    {
        return 'record';
    }

    public function subjectId(): string
    {
        return $this->quotationId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.quotation_revised',
            actorId: $this->actorId,
            targetType: 'quotation',
            targetId: $this->quotationId,
            reason: trim($this->reason),
            metadata: ['field' => $this->field, 'quotation_line_id' => $this->quotationLineId],
        );
    }

    private function assertNewValueShape(): void
    {
        $value = $this->newValue;

        if (in_array($this->field, ['unit_price', 'tax_amount', 'discount_amount'], true)) {
            if ($value === null || ! is_numeric($value) || (float) $value < 0) {
                throw new InvalidArgumentException("ReviseQuotation {$this->field} \$newValue must be a non-negative number.");
            }
        } elseif ($this->field === 'offered_qty') {
            if ($value === null || ! is_numeric($value) || (float) $value <= 0) {
                throw new InvalidArgumentException('ReviseQuotation offered_qty $newValue must be a positive number (§39).');
            }
        } elseif ($this->field === 'lead_time_days') {
            if ($value !== null && (! ctype_digit($value))) {
                throw new InvalidArgumentException('ReviseQuotation lead_time_days $newValue must be a non-negative integer or null.');
            }
        }
    }
}
