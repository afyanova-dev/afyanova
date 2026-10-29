<?php

declare(strict_types=1);

/**
 * Insurance & Payer permission keys (21-insurance-and-payer-domain.md §278,
 * §279; ADR-198 D-A; ADR-202 D-D).
 *
 * A NEW `insurance` permission segment. Canonical 3-segment
 * `{domain}.{resource}.{action}` per IC §3 / §12 + ADR-122. Aggregated into
 * PermissionRegistry by `InsuranceServiceProvider::boot()`.
 *
 * Keys:
 *   - insurance.access         — read the module (payer + plan masters;
 *                                Ins.2+ coverage / authorization / claim reads).
 *   - insurance.payers.manage  — register / update / (de)activate a payer
 *                                (`RegisterPayer` / `UpdatePayer` /
 *                                `SetPayerStatus`). Tenant-wide — a payer is
 *                                organization-level configuration (§219 / §220).
 *                                (Ins.1 — ADR-198)
 *   - insurance.plans.manage   — create / update / (de)activate a payer plan
 *                                (`CreatePayerPlan` / `UpdatePayerPlan` /
 *                                `SetPayerPlanStatus`). Tenant-wide. A DISTINCT
 *                                key from `.payers.manage` (§278) — a single
 *                                policy cannot distinguish two class-level
 *                                creates. (Ins.1 — ADR-198)
 *   - insurance.members.manage — register / update / terminate a patient's
 *                                coverage under a plan (`RegisterMemberCoverage`
 *                                / `UpdateMemberCoverage` /
 *                                `TerminateMemberCoverage`). Tenant-wide — a
 *                                patient's insurance membership is not
 *                                facility-specific (§156). (Ins.2 — ADR-199)
 *   - insurance.coverage.manage — set / end a plan's coverage rule for a
 *                                service category (`SetPlanCoverageRule` /
 *                                `EndPlanCoverageRule`). Tenant-wide;
 *                                commercially sensitive (§154 / §281) — a
 *                                DISTINCT key from `.plans.manage`. The
 *                                `EvaluateCoverage` / `GetPlanCoverageRules`
 *                                queries gate on the coarse `insurance.access`
 *                                (§278's `insurance.coverage.evaluate` folds
 *                                into it — queries gate on `.access`).
 *                                (Ins.3 — ADR-200)
 *   - insurance.authorizations.request — request a pre-authorization for a service
 *                                under member coverage (`RequestAuthorization`).
 *                                Tenant-wide. (Ins.5 — ADR-202)
 *   - insurance.authorizations.approve — record a payer's decision on a
 *                                pre-authorization (`ApproveAuthorization`,
 *                                `DenyAuthorization`, `CancelAuthorization`).
 *                                Tenant-wide. (Ins.5 — ADR-202)
 *
 *   - insurance.contracts.manage — create / activate / terminate a provider-payer
 *                                contract (`CreatePayerContract` /
 *                                `ActivatePayerContract` /
 *                                `TerminatePayerContract`). Tenant-wide. (Ins.6 — ADR-203)
 *   - insurance.tariffs.manage — set / end agreed payer-contracted tariff prices
 *                                (`SetPayerTariff` / `EndPayerTariff`). Commercially
 *                                sensitive pricing schedule (§154 / §281). (Ins.6 — ADR-203)
 *   - insurance.claims.manage   — capture / update / cancel / supersede claims
 *                                (`CreateClaimFromInvoice` / `UpdateClaimDraft` /
 *                                `ValidateClaimReadiness` / `CancelClaim` /
 *                                `CreateReplacementClaim`). Tenant-wide. (Ins.7 — ADR-204)
 *   - insurance.claims.submit   — formally submit a claim to a payer
 *                                (`SubmitClaim`). Tenant-wide. (Ins.7 — ADR-204)
 *   - insurance.adjudications.manage — record, view, and manage claim adjudications
 *                                (`RecordClaimAdjudication`). Tenant-wide. (Ins.8 — ADR-205)
 *   - insurance.remittance.record — record, match, and reconcile remittances
 *                                (`RecordRemittance` / `ReconcileRemittanceLine`).
 *                                Tenant-wide. (Ins.8 — ADR-205)
 *
 * @return list<string>
 */
return [
    'insurance.access',
    'insurance.payers.manage',
    'insurance.plans.manage',
    'insurance.members.manage',
    'insurance.coverage.manage',
    'insurance.authorizations.request',
    'insurance.authorizations.approve',
    'insurance.contracts.manage',
    'insurance.tariffs.manage',
    'insurance.claims.manage',
    'insurance.claims.submit',
    'insurance.adjudications.manage',
    'insurance.remittance.record',
];
