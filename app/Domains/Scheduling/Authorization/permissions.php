<?php

declare(strict_types=1);

/**
 * PHASE 11 (increments SCH.1 & SCH.2) — permission definitions for Domain 22 (Scheduling & Appointments).
 * (22-scheduling-and-appointments-domain.md §286).
 *
 * @return list<string>
 */
return [
    'sch.access',
    'sch.appointment_type.manage',
    'sch.resource.manage',
    'sch.schedule.manage',
    'sch.slot.generate',
    'sch.slot.hold',
    'sch.appointment.book',
    'sch.appointment.manage',
    'sch.appointment.record_arrival',
    'sch.waitlist.manage',
];
