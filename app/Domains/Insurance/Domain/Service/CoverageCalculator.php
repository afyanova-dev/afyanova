<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Service;

use App\Domains\Insurance\Domain\Money;
use App\Domains\Insurance\Domain\ValueObject\CoverageRuleOutcome;

/**
 * The ILLUSTRATIVE payer/patient split for a charge under one coverage rule
 * (21-insurance-and-payer-domain.md §34, §35, §40, §41, §43; ADR-200 D-C).
 *
 * Pure. Scale-2, HALF-UP (`Insurance\Domain\Money`). The result is an ESTIMATE
 * — the authoritative split is Billing's at Ins.4 (ADR-112). The
 * payer-CONTRACTED allowed-amount cap (§51–§55) is Ins.6 and will amend this.
 */
final class CoverageCalculator
{
    /**
     * @param  numeric-string  $chargeAmount  already `Money::of()`-normalised, non-negative
     * @param  numeric-string|null  $coPayAmount  already `Money::of()`-normalised
     * @param  numeric-string|null  $fixedBenefitAmount  already `Money::of()`-normalised
     * @param  numeric-string|null  $allowedAmount  already `Money::of()`-normalised
     * @return array{payer: numeric-string, patient: numeric-string, allowedAmount: numeric-string|null, contractualAdjustment: numeric-string}
     */
    public function split(
        string $coverageStatus,
        ?int $coveragePercent,
        ?string $coPayAmount,
        ?string $fixedBenefitAmount,
        string $chargeAmount,
        ?string $allowedAmount = null,
    ): array {
        if ($coverageStatus === CoverageRuleOutcome::NOT_COVERED) {
            return [
                'payer' => Money::zero(),
                'patient' => Money::of($chargeAmount),
                'allowedAmount' => null,
                'contractualAdjustment' => Money::zero(),
            ];
        }

        $normalisedCharge = Money::of($chargeAmount);

        if ($allowedAmount !== null) {
            $effectiveAllowed = Money::of($allowedAmount);
            $payableBase = Money::min($effectiveAllowed, $normalisedCharge);
            $contractualAdjustment = Money::clampToZero(Money::subtract($normalisedCharge, $payableBase));
            $resolvedAllowed = $payableBase;
        } else {
            $payableBase = $normalisedCharge;
            $contractualAdjustment = Money::zero();
            $resolvedAllowed = null;
        }

        // COVERED and REQUIRES_AUTHORIZATION compute the same estimate; the
        // caller flags REQUIRES_AUTHORIZATION as PROVISIONAL confidence.
        if ($fixedBenefitAmount !== null) {
            $payer = Money::min($fixedBenefitAmount, $payableBase);

            return [
                'payer' => $payer,
                'patient' => Money::subtract($payableBase, $payer),
                'allowedAmount' => $resolvedAllowed,
                'contractualAdjustment' => $contractualAdjustment,
            ];
        }

        $coPay = $coPayAmount !== null ? Money::of($coPayAmount) : Money::zero();
        $base = Money::clampToZero(Money::subtract($payableBase, $coPay));

        $payer = $coveragePercent !== null
            ? Money::percentageOf($base, $coveragePercent)
            : $base;  // COVERED with no percent = fully covered net of the co-pay

        $payer = Money::min($payer, $payableBase);

        return [
            'payer' => $payer,
            'patient' => Money::subtract($payableBase, $payer),
            'allowedAmount' => $resolvedAllowed,
            'contractualAdjustment' => $contractualAdjustment,
        ];
    }
}
