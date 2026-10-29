<?php

declare(strict_types=1);

/**
 * PHASE 12 (Increment P12.3) — Documents permissions.
 * Shapes: {domain}.access or {domain}.{resource}.{action}
 *
 * @return list<string>
 */
return [
    'documents.access',
    'documents.document.upload',
    'documents.document.generate',
    'documents.document.view',
    'documents.document.download',
    'documents.document.sign',
    'documents.document.redact',
    'documents.document.dispose',
    'documents.template.manage',
    'documents.retention.manage',
    'documents.grant.manage',
];
