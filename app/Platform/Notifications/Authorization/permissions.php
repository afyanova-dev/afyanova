<?php

declare(strict_types=1);

/**
 * PHASE 12 (Increment P12.2) — Notifications permissions.
 * Shapes: {domain}.access or {domain}.{resource}.{action}
 *
 * @return list<string>
 */
return [
    'notifications.access',
    'notifications.message.send',
    'notifications.message.bulk',
    'notifications.template.manage',
    'notifications.preference.manage',
    'notifications.delivery.view',
];
