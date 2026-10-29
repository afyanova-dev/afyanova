<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One-time presentation outcome for a completed Reception check-in.
 *
 * It is deliberately not Queueing state: `UNCONFIRMED` means that the
 * Encounter was created but this request received no Queueing result.
 */
#[TypeScript]
final readonly class ReceptionCheckInOutcome
{
    /**
     * @param  array{status: 'NOT_REQUESTED'|'PLACED'|'UNCONFIRMED', queueId: string|null, ticketNumber: string|null}  $queuePlacement
     */
    public function __construct(
        public string $encounterNumber,
        #[LiteralTypeScriptType("{ status: 'NOT_REQUESTED' | 'PLACED' | 'UNCONFIRMED'; queueId: string | null; ticketNumber: string | null; }")]
        public array $queuePlacement,
    ) {}

    public static function notRequested(string $encounterNumber): self
    {
        return new self($encounterNumber, [
            'status' => 'NOT_REQUESTED',
            'queueId' => null,
            'ticketNumber' => null,
        ]);
    }

    public static function placed(string $encounterNumber, string $queueId, string $ticketNumber): self
    {
        return new self($encounterNumber, [
            'status' => 'PLACED',
            'queueId' => $queueId,
            'ticketNumber' => $ticketNumber,
        ]);
    }

    public static function unconfirmed(string $encounterNumber, string $queueId): self
    {
        return new self($encounterNumber, [
            'status' => 'UNCONFIRMED',
            'queueId' => $queueId,
            'ticketNumber' => null,
        ]);
    }

    /**
     * @return array{encounterNumber: string, queuePlacement: array{status: 'NOT_REQUESTED'|'PLACED'|'UNCONFIRMED', queueId: string|null, ticketNumber: string|null}}
     */
    public function toArray(): array
    {
        return [
            'encounterNumber' => $this->encounterNumber,
            'queuePlacement' => $this->queuePlacement,
        ];
    }
}
