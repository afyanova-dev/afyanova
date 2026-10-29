<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

/**
 * Approved permission set for the Nurse staff role. Mirrors the demo
 * seeder composition exactly (clinical nurse defaults + encounter read +
 * patient read + frontline queue set) — the seeder and this definition
 * must not drift apart; the provisioner reconciles toward this set.
 */
final class NurseRoleDefinition
{
    public const KEY = 'nurse';

    public const PERMISSIONS = [
        'clinical.access',
        'clinical.notes.read',
        'clinical.notes.create',
        'clinical.triage.record',
        'clinical.triage.read',
        'clinical.vitals.read',
        'clinical.vitals.record',
        'clinical.procedures.read',
        'clinical.procedures.perform',
        'clinical.procedures.complete',
        'encounter.encounters.read',
        'patient.patients.read',
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
