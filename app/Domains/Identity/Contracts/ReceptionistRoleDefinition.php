<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

/**
 * Approved permission set for the Receptionist staff role. Mirrors the demo
 * seeder composition exactly (front-desk encounter/scheduling/patient
 * intake + clinical documentation-status read + frontline queue set).
 */
final class ReceptionistRoleDefinition
{
    public const KEY = 'receptionist';

    public const PERMISSIONS = [
        'encounter.encounters.read',
        'sch.access',
        'patient.patients.read',
        'encounter.encounters.create',
        'encounter.encounters.update',
        'encounter.encounters.manage_disposition',
        'encounter.encounters.complete',
        'organization.structure.read',
        'clinical.documentation_status.read',
        'sch.appointment.record_arrival',
        'sch.appointment.book',
        'sch.appointment.manage',
        'care_coordination.access',
        'patient.patients.create',
        'patient.patients.update',
        'patient.patients.override_duplicate',
        'patient.identifiers.manage',
        'patient.identifiers.view_sensitive',
        'queue.access',
        'queue.ticket.view',
        'queue.ticket.place',
        'queue.ticket.call',
        'queue.ticket.service',
        'queue.ticket.hold',
        'queue.ticket.transfer',
        'queue.ticket.skip',
    ];
}
