<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Patient sex — a controlled vocabulary, not free text (10-patient-domain.md
 * §30: "The data model should use a well-defined controlled vocabulary").
 *
 * §30 explicitly defers the exact value set ("should be defined carefully and
 * consistently across V4") rather than enumerating it. This is the minimal,
 * standard HMIS/DHIS2 administrative set (Male / Female / Unknown) — a value
 * choice within a canon-deferred area, not an architectural decision. It is
 * additive-safe: a later ADR can extend the case list without breaking
 * existing data (IC §7.1 event-versioning precedent for additive change).
 */
#[TypeScript]
enum Sex: string
{
    case Male = 'MALE';
    case Female = 'FEMALE';
    case Unknown = 'UNKNOWN';
}
