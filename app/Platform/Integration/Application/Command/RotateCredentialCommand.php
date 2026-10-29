<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Domain\Enum\CredentialAlgorithm;
use Carbon\CarbonImmutable;

final readonly class RotateCredentialCommand
{
    public function __construct(
        public string $connectorId,
        public CredentialAlgorithm $algorithm,
        public string $secretRef,
        public ?string $keyIdentifier = null,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
