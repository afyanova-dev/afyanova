<?php

declare(strict_types=1);

/**
 * Theatre permission keys (17-theatre-domain.md §137; ADR-150 D-A/D-I,
 * ADR-152 D-A, ADR-155 D-H, ADR-156 D-G).
 *
 * Lower snake case per IC §3/§12 + ADR-122. Aggregated into PermissionRegistry
 * by TheatreServiceProvider::boot(). 18 keys total — T.1: 8; T.2 (ADR-152):
 * +2 OperativeNote; T.4 (ADR-155): +2 AnaesthesiaRecord; T.3 (ADR-156): +4
 * (procedures / recovery x2 / case-events); T.6 (ADR-164): +2 SurgicalSpecimen
 * (`.specimens.record` for collect/label, `.specimens.handoff` for
 * hand-off/mark-received). theatre.reports.* is still deferred.
 *
 * @return list<string>
 */
return [
    'theatre.access',
    'theatre.cases.create',
    'theatre.cases.schedule',
    'theatre.rooms.manage',
    'theatre.team.manage',
    'theatre.checklists.manage',
    'theatre.cases.execute',
    'theatre.cases.cancel',
    'theatre.operative_notes.author',
    'theatre.operative_notes.sign',
    'theatre.anaesthesia.record',
    'theatre.anaesthesia.sign',
    'theatre.procedures.record',
    'theatre.recovery.handoff',
    'theatre.recovery.acknowledge',
    'theatre.case_events.record',
    'theatre.specimens.record',
    'theatre.specimens.handoff',
];
