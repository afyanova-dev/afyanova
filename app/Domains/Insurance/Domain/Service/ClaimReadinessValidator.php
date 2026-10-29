<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Service;

use App\Domains\Insurance\Domain\AuthorizationReader;
use App\Domains\Insurance\Domain\Exception\AuthorizationNotFound;
use App\Domains\Insurance\Domain\Exception\MemberCoverageNotFound;
use App\Domains\Insurance\Domain\MemberCoverageReader;
use App\Domains\Insurance\Domain\Model\Claim;
use App\Domains\Insurance\Domain\Model\ClaimLine;
use App\Domains\Insurance\Domain\PlanCoverageRuleReader;
use App\Domains\Insurance\Domain\ValueObject\AuthorizationStatus;
use App\Domains\Insurance\Domain\ValueObject\ClaimReadinessStatus;
use App\Domains\Insurance\Domain\ValueObject\MemberCoverageStatus;
use App\Domains\Patient\Contracts\PatientApi;
use Carbon\CarbonImmutable;

/**
 * Deterministic, local claim readiness validation engine (21-insurance-and-payer-domain.md
 * §80, §217, §218; ADR-204).
 *
 * Evaluates strictly local, verifiable domain facts:
 *   1. Non-empty active claim lines.
 *   2. Primary diagnosis code provided.
 *   3. Patient exists in current tenant.
 *   4. Member coverage is ACTIVE and was effective on the claim service dates.
 *   5. Pre-authorization verified for service categories requiring it under plan coverage rules.
 */
final readonly class ClaimReadinessValidator
{
    public function __construct(
        private PatientApi $patientApi,
        private MemberCoverageReader $coverageReader,
        private PlanCoverageRuleReader $ruleReader,
        private AuthorizationReader $authorizationReader,
    ) {}

    /**
     * @param  list<ClaimLine>  $lines
     * @return array{isReady: bool, status: string, blockingReasons: list<string>, notes: array<string, mixed>}
     */
    public function validate(Claim $claim, array $lines): array
    {
        $blockingReasons = [];
        $missingInformation = [];

        // 1. Must have lines
        $activeLines = array_filter($lines, fn (ClaimLine $line): bool => $line->line_active);
        if (empty($activeLines)) {
            $blockingReasons[] = 'Claim has no active claim lines (NO_LINES)';
        }

        // 2. Primary diagnosis code
        if ($claim->primary_diagnosis_code === null || trim($claim->primary_diagnosis_code) === '') {
            $missingInformation[] = 'Primary diagnosis code is missing (MISSING_DIAGNOSIS)';
            $blockingReasons[] = 'Primary diagnosis code is required for submission (MISSING_DIAGNOSIS)';
        }

        // 3. Patient existence
        if (! $this->patientApi->patientExists($claim->patient_id)) {
            $blockingReasons[] = 'Patient not found in current tenant (PATIENT_NOT_FOUND)';
        }

        // 4. Member coverage validity
        try {
            $coverage = $this->coverageReader->summary($claim->member_coverage_id);
        } catch (MemberCoverageNotFound) {
            $coverage = null;
        }

        if ($coverage === null || $coverage->status !== MemberCoverageStatus::ACTIVE) {
            $blockingReasons[] = 'Patient member coverage is not ACTIVE (INACTIVE_COVERAGE)';
        } else {
            // Verify service dates fall within coverage window
            $from = CarbonImmutable::parse($coverage->effectiveFrom)->startOfDay();
            $to = $coverage->effectiveTo !== null ? CarbonImmutable::parse($coverage->effectiveTo)->startOfDay() : null;

            foreach ($activeLines as $line) {
                $serviceDate = CarbonImmutable::parse($line->service_date)->startOfDay();

                if ($serviceDate->lt($from) || ($to !== null && $serviceDate->gt($to))) {
                    $blockingReasons[] = sprintf(
                        'Service date [%s] on line %d is outside member coverage effective window [%s to %s] (COVERAGE_DATE_MISMATCH)',
                        $line->service_date,
                        $line->line_number,
                        $from->toDateString(),
                        $to?->toDateString() ?? 'open',
                    );
                    break;
                }
            }
        }

        // 5. Pre-authorization verification if required by plan rules
        $authorization = null;
        if ($claim->authorization_id !== null) {
            try {
                $authorization = $this->authorizationReader->summary($claim->authorization_id);
            } catch (AuthorizationNotFound) {
                $authorization = null;
            }
        }

        foreach ($activeLines as $line) {
            $rule = $this->ruleReader->effectiveRule(
                $claim->plan_id,
                $line->service_category,
                $line->service_date,
            );

            if ($rule?->authorizationRequired) {
                if ($authorization === null) {
                    $blockingReasons[] = sprintf(
                        'Service category [%s] on line %d requires pre-authorization under plan rules, but no authorization is linked (PREAUTH_REQUIRED_MISSING)',
                        $line->service_category,
                        $line->line_number,
                    );
                } elseif (! in_array($authorization->status, AuthorizationStatus::EFFECTIVE, true)) {
                    $blockingReasons[] = sprintf(
                        'Linked authorization [%s] is in status [%s], but requires APPROVED/PARTIALLY_APPROVED (PREAUTH_NOT_APPROVED)',
                        $authorization->authorizationNumber,
                        $authorization->status,
                    );
                } else {
                    // Check validity window
                    $serviceDate = CarbonImmutable::parse($line->service_date)->startOfDay();
                    $validFrom = $authorization->validFrom !== null ? CarbonImmutable::parse($authorization->validFrom)->startOfDay() : null;
                    $validTo = $authorization->validTo !== null ? CarbonImmutable::parse($authorization->validTo)->startOfDay() : null;

                    if ($validFrom !== null && $serviceDate->lt($validFrom)) {
                        $blockingReasons[] = sprintf(
                            'Service date [%s] is before authorization valid_from [%s] (PREAUTH_DATE_MISMATCH)',
                            $line->service_date,
                            $authorization->validFrom,
                        );
                    }
                    if ($validTo !== null && $serviceDate->gt($validTo)) {
                        $blockingReasons[] = sprintf(
                            'Service date [%s] is after authorization valid_to [%s] (PREAUTH_EXPIRED)',
                            $line->service_date,
                            $authorization->validTo,
                        );
                    }
                }
            }
        }

        $isReady = empty($blockingReasons);
        $status = $isReady
            ? (! empty($missingInformation) ? ClaimReadinessStatus::MISSING_INFORMATION : ClaimReadinessStatus::READY)
            : ClaimReadinessStatus::BLOCKED;

        return [
            'isReady' => $isReady,
            'status' => $status,
            'blockingReasons' => array_values(array_unique($blockingReasons)),
            'notes' => [
                'blocking_reasons' => array_values(array_unique($blockingReasons)),
                'missing_information' => array_values(array_unique($missingInformation)),
                'checked_at' => CarbonImmutable::now()->toIso8601String(),
            ],
        ];
    }
}
