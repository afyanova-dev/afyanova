<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\CashSessionReader;
use App\Domains\Billing\Domain\CashSessionRepository;
use App\Domains\Billing\Domain\Money;

/**
 * Orchestrates `CloseCashSession` (ADR-191 C6-B / G-4).
 *
 * `getByIdForUpdate` locks the session — this serialises with `RecordPayment`
 * (which also locks the session when `method = CASH`), so the CASH-payment sum
 * is consistent. `expectedCash = openingFloat + Σ(CASH RECORDED payments in
 * the session)` (via the reader, run inside this locked transaction);
 * `variance = countedCash − expectedCash`; `CashSession::close` freezes all
 * three. C6-B's non-terminal-payment guard is vacuous until gateway payments
 * exist (every Bill.5 payment is RECORDED on creation).
 */
final class CloseCashSessionHandler
{
    public function __construct(
        private readonly CashSessionRepository $sessions,
        private readonly CashSessionReader $reader,
    ) {}

    public function __invoke(CloseCashSession $command): void
    {
        $session = $this->sessions->getByIdForUpdate($command->cashSessionId);

        $expectedCash = Money::of($this->reader->summary($command->cashSessionId)->expectedCash);
        $countedCash = Money::of($command->countedCash);
        $variance = Money::subtract($countedCash, $expectedCash);

        $session->close($expectedCash, $countedCash, $variance, $command->actorId, $command->note);

        $this->sessions->save($session);
    }
}
