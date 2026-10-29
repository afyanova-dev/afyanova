<?php

declare(strict_types=1);

/**
 * Care Coordination permission keys (26-care-coordination-domain.md §13;
 * ADR-151 D-A/D-K, ADR-153 D-H, ADR-154 D-H).
 *
 * Lower snake case per IC §3/§12 + ADR-122 — corrects the domain doc's own
 * hyphenated `care-coordination.*` sketch (the same fix Bed & Ward's
 * `bed-ward.*` needed). Aggregated into PermissionRegistry by
 * CareCoordinationServiceProvider::boot(). 7 keys total — CC.1 shipped 5;
 * CC.2 (ADR-153) adds `.episode.manage`; CC.3a (ADR-154) adds
 * `.followup.manage`. `.pathway.enrol` and `.episode.coordinator.assign` are
 * CC.3b/CC.3c concerns, not built yet.
 *
 * @return list<string>
 */
return [
    'care_coordination.access',
    'care_coordination.referral.send',                  // outbound actor — folds create/cancel
    'care_coordination.referral.receive',                // inbound actor — folds receive/accept/reject/complete/link
    'care_coordination.handover.acknowledge',
    'care_coordination.feedback.record',
    'care_coordination.episode.manage',                  // CC.2 — folds open/close/transfer-out/link/detach
    'care_coordination.followup.manage',                 // CC.3a — folds schedule/record-outcome
];
