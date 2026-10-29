<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

final readonly class RecordReturnAcknowledgementCommand
{
    /**
     * @param  array<string, mixed>|null  $receiptPayload
     */
    public function __construct(
        public string $tenantId,
        public string $returnId,
        public ?string $acknowledgementRef = null,
        public ?array $receiptPayload = null,
    ) {}
}
