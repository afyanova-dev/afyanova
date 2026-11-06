<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one laboratory test catalog entry
 * (ADR-157 D-G). Returned by `GetLabTestDefinition` / `GetLabTestDefinitions`
 * and consumed intra-domain by `CreateLabOrderHandler` to snapshot the code /
 * name / version and inspect the availability / capability flags. NOT an event
 * payload.
 */
final readonly class LabTestDefinitionSummary
{
    public const AVAILABILITY_AVAILABLE = 'AVAILABLE';

    public const AVAILABILITY_DISABLED = 'DISABLED';

    public const AVAILABILITY_REFERRAL_ONLY = 'REFERRAL_ONLY';

    public const AVAILABILITY_TEMPORARILY_UNAVAILABLE = 'TEMPORARILY_UNAVAILABLE';

    /**
     * @param  list<array<string, mixed>>|null  $parameters
     */
    public function __construct(
        public string $definitionId,
        public string $code,
        public string $name,
        public ?string $category,
        public ?string $description,
        public ?string $specimenType,
        public ?string $containerType,
        public ?array $parameters,
        public ?string $requiredCapabilityCode,
        public string $availabilityStatus,
        public ?int $turnaroundTargetMinutes,
        public bool $active,
        public int $version,
    ) {}
}
