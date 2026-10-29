<?php

declare(strict_types=1);

/**
 * PHASE 12 (Increment P12.1) — Queueing & Patient Flow permissions.
 * Shapes: {domain}.access or {domain}.{resource}.{action}
 *
 * @return list<string>
 */
return [
    'queue.access',
    'queue.ticket.view',
    'queue.ticket.place',
    'queue.ticket.call',
    'queue.ticket.service',
    'queue.ticket.hold',
    'queue.ticket.transfer',
    'queue.ticket.skip',
    'queue.movement.view',
    'queue.movement.record',
    'queue.definition.manage',
];
