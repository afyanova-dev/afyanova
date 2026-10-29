<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Application\ProcurementContextResolver;
use App\Domains\Procurement\Domain\ContractRepository;
use App\Domains\Procurement\Domain\Exception\ContractAmendmentInvalid;
use App\Domains\Procurement\Domain\Exception\ContractCeilingBelowCommitted;
use App\Domains\Procurement\Domain\Exception\ContractExpiryNotExtended;
use App\Domains\Procurement\Domain\Model\Contract;
use App\Domains\Procurement\Domain\Model\ContractAmendment;
use App\Domains\Procurement\Domain\Model\ContractLine;
use App\Domains\Procurement\Domain\Money;
use App\Domains\Procurement\Domain\Quantity;
use App\Domains\Procurement\Domain\Service\ContractLifecycle;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Orchestrates `AmendContract` (ADR-178 D-C / D-D / D-E).
 *
 * Load the contract `FOR UPDATE` → it must be ACTIVE → apply each change in
 * order, appending one `pcr_contract_amendments` row per change (sequence =
 * prior max + 1) → `Contract::recordAmendment()` bumps `amendment_count` so the
 * row is always dirty and `version` advances → save. One bus transaction; no
 * event. INV-price is untouched (no price change is possible); INV-33a cannot be
 * retroactively broken (a ceiling can only be lowered to ≥ the line's committed).
 *
 * `ContractRepository` is the first repository parameter → `ContractPolicy::manage`.
 */
final class AmendContractHandler
{
    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly ContractLifecycle $lifecycle,
        private readonly ProcurementContextResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(AmendContract $command): void
    {
        $contract = $this->contracts->getByIdForUpdate($command->contractId);

        $this->lifecycle->assertCanAmend((string) $contract->status);

        $tenantId = $this->context->tenantId();
        $sequence = (int) (ContractAmendment::query()->where('contract_id', $command->contractId)->max('sequence') ?? 0);
        $nextLineNo = (int) (ContractLine::query()->where('contract_id', $command->contractId)->max('line_no') ?? 0);

        foreach ($command->changes as $change) {
            $sequence++;
            /** @var string $field */
            $field = $change['field'];

            if ($field === AmendContract::FIELD_ADD_LINE) {
                $nextLineNo++;
                [$type, $lineId, $fieldChanged, $previousValue, $newValue] = $this->applyAddLine($command, $tenantId, $nextLineNo, $change);
            } else {
                [$type, $lineId, $fieldChanged, $previousValue, $newValue] = match ($field) {
                    AmendContract::FIELD_EXPIRY_DATE => $this->applyExpiry($contract, $command, $change),
                    AmendContract::FIELD_TERMS => $this->applyTerms($contract, $change),
                    AmendContract::FIELD_TITLE => $this->applyTitle($contract, $change),
                    default => $this->applyCeiling($command, $change), // FIELD_QUANTITY_CEILING
                };
            }

            ContractAmendment::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'contract_id' => $command->contractId,
                'sequence' => $sequence,
                'amendment_type' => $type,
                'contract_line_id' => $lineId,
                'field_changed' => $fieldChanged,
                'previous_value' => $previousValue,
                'new_value' => $newValue,
                'reason' => trim($command->reason),
                'amended_by_user_id' => $command->actorId,
                'amended_at' => CarbonImmutable::now(),
            ]);
        }

        $contract->recordAmendment();

        $this->contracts->save($contract);
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array{0: string, 1: string|null, 2: string, 3: string|null, 4: string|null}
     */
    private function applyExpiry(Contract $contract, AmendContract $command, array $change): array
    {
        $current = $contract->expiry_date->toDateString();
        $target = CarbonImmutable::parse((string) $change['value'])->toDateString();

        if ($target <= $current) {
            throw ContractExpiryNotExtended::forContract($command->contractId, $current, $target);
        }

        $contract->expiry_date = CarbonImmutable::parse($target);

        return [ContractAmendment::TYPE_EXPIRY, null, 'expiry_date', $current, $target];
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array{0: string, 1: string|null, 2: string, 3: string|null, 4: string|null}
     */
    private function applyTerms(Contract $contract, array $change): array
    {
        $previous = $contract->terms;
        $value = $change['value'] ?? null;
        $contract->terms = is_string($value) && trim($value) !== '' ? trim($value) : null;

        return [ContractAmendment::TYPE_TERMS, null, 'terms', $previous, $contract->terms];
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array{0: string, 1: string|null, 2: string, 3: string|null, 4: string|null}
     */
    private function applyTitle(Contract $contract, array $change): array
    {
        $previous = (string) $contract->title;
        $contract->title = trim((string) $change['value']);

        return [ContractAmendment::TYPE_TITLE, null, 'title', $previous, $contract->title];
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array{0: string, 1: string|null, 2: string, 3: string|null, 4: string|null}
     */
    private function applyCeiling(AmendContract $command, array $change): array
    {
        $lineId = (string) $change['contractLineId'];

        /** @var ContractLine|null $line */
        $line = ContractLine::query()
            ->whereKey($lineId)
            ->where('contract_id', $command->contractId)
            ->first();
        if ($line === null) {
            throw ContractAmendmentInvalid::unknownLine($command->contractId, $lineId);
        }

        $rawValue = $change['value'] ?? null;
        $newCeiling = $rawValue !== null ? Quantity::of((string) $rawValue) : null;

        if ($newCeiling !== null) {
            $committed = $this->contracts->committedQuantityForContractLine($lineId);
            if (! Quantity::gte($newCeiling, $committed)) {
                throw ContractCeilingBelowCommitted::forContractLine($lineId, $newCeiling, $committed);
            }
        }

        $previous = $line->quantity_ceiling !== null ? (string) $line->quantity_ceiling : null;
        $line->quantity_ceiling = $newCeiling;
        $line->save();

        return [ContractAmendment::TYPE_CEILING, $lineId, 'quantity_ceiling', $previous, $newCeiling];
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array{0: string, 1: string|null, 2: string, 3: string|null, 4: string|null}
     */
    private function applyAddLine(AmendContract $command, string $tenantId, int $lineNo, array $change): array
    {
        /** @var array<string, mixed> $line */
        $line = $change['line'];
        $resolved = $this->resolver->resolveLine($line, $lineNo);

        $newLineId = (string) Str::uuid7();
        ContractLine::create([
            'id' => $newLineId,
            'tenant_id' => $tenantId,
            'contract_id' => $command->contractId,
            'line_no' => $lineNo,
            'line_type' => $resolved['lineType'],
            'item_ref' => $resolved['itemRef'],
            'item_code' => $resolved['itemCode'],
            'item_name' => $resolved['itemName'],
            'description' => $resolved['description'],
            'agreed_unit_price' => Money::of((string) $line['agreedUnitPrice']),
            'quantity_ceiling' => isset($line['quantityCeiling']) && trim((string) $line['quantityCeiling']) !== ''
                ? Quantity::of((string) $line['quantityCeiling'])
                : null,
            'uom' => $resolved['uom'],
        ]);

        return [ContractAmendment::TYPE_LINE_ADDED, $newLineId, 'line', null, (string) $lineNo];
    }
}
