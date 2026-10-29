<?php

declare(strict_types=1);

/**
 * PHASE 14 (Increment P12.4) — Reporting & Analytics permissions.
 * Shapes: {domain}.access or {domain}.{resource}.{action}
 *
 * @return list<string>
 */
return [
    'reporting.access',
    'reporting.dashboard.view',
    'reporting.report.run',
    'reporting.report.define',
    'reporting.report.schedule',
    'reporting.selfservice.query',
    'reporting.mart.curate',
    'reporting.statutory.build',
    'reporting.statutory.review',
    'reporting.statutory.submit',
    'reporting.metric.manage',
    'reporting.projection.manage',
    'reporting.admin.manage',
];
