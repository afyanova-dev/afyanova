<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Dto;

/**
 * One verified patient identifier surfaced by the Patient Glance read model.
 * Display-only (ADR-125) — values are full; the HTTP layer applies
 * view_sensitive masking per caller permission (Presentation concern).
 */
final readonly class PatientGlanceIdentifier
{
    public function __construct(
        public string $id,
        public string $type,
        public string $value,
        public bool $isVerified,
    ) {}
}
