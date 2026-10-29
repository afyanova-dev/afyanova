<?php

declare(strict_types=1);

/**
 * Encounter permission keys (docs/00-foundation/03-identity-rbac.md §31–33, §66;
 * 11-encounter-domain.md §113; ADR-130). L1 `.access` + L2 `{resource}.{action}`.
 * Aggregated into PermissionRegistry by EncounterServiceProvider::boot().
 *
 * ADR-130's full PHASE 5 set is `encounter.encounters.{read, create, update,
 * transfer, complete, cancel, reopen, admit, manage_disposition,
 * assign_location}` (lower snake per IC §3/§12 + ADR-122 KEY CASING — ADR-130's
 * PERMISSIONS list and §113 write `manage_disposition` / `assign_location` with
 * a hyphen, a surface inconsistency with the naming authority). ADR-130 omits
 * `abandon` from that list — a known illustrative gap (same class as it omitting
 * `EncounterAdmitted` from §103), absorbed by E.10. With `read` landing in E.12
 * (the `GetEncounter` query), the ADR-130 PHASE 5 set is complete.
 *
 * @return list<string>
 */
return [
    'encounter.access',
    'encounter.encounters.read',               // GetEncounter (E.12) — protected by-id read; ADR-130 / IC §6.2
    'encounter.encounters.create',             // CreateEncounter (E.1) — ADR-104 / ADR-130
    'encounter.encounters.update',             // RecordArrival (E.2) / StartEncounter (E.3) — routine lifecycle progression; ADR-130
    'encounter.encounters.admit',              // AdmitEncounter (E.4) — the sanctioned type conversion; ADR-120 / ADR-130
    'encounter.encounters.manage_disposition', // ChangeEncounterDisposition (E.5) — record / revise disposition; ADR-130 A
    'encounter.encounters.assign_location',    // AssignEncounterLocation (E.6) — coarse care-context department / unit; ADR-130 B
    'encounter.encounters.complete',           // CompleteEncounter (E.7) — close the attendance; ADR-121 / ADR-130
    'encounter.encounters.reopen',             // ReopenEncounter (E.8) — reopen a completed attendance; ADR-121 / ADR-130 C
    'encounter.encounters.cancel',             // CancelEncounter (E.9) — cancel an unstarted attendance; ADR-121 / ADR-130
    'encounter.encounters.abandon',            // AbandonEncounter (E.10) — abandon an attendance; ADR-121 / ADR-130
    'encounter.encounters.transfer',           // TransferEncounter (E.11) — transfer the encounter out to another facility; ADR-121 / ADR-130
    'encounter.encounters.link_relationship',  // RecordEncounterRelationship (ADR-151 D-C) — cross-domain write via EncounterApi
];
