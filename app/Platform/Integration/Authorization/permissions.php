<?php

declare(strict_types=1);

/**
 * PHASE 13 (Increment P13.1) — Integration Platform Service permissions.
 * Shapes: {domain}.access or {domain}.{resource}.{action}
 *
 * @return list<string>
 */
return [
    'integration.access',
    'integration.connector.manage',
    'integration.credential.rotate',
    'integration.messages.view',
    'integration.deadletter.resolve',
    'integration.mapping.manage',
    'integration.webhook.manage',
    'integration.import.reconcile',
];
