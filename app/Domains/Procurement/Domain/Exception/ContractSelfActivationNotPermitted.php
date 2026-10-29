<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `ActivateContract` was dispatched by the contract's creator — activation is a
 * commercial commitment and requires a different actor (§24 / §228 SoD;
 * ADR-176 D-C). Maps to HTTP 409.
 */
final class ContractSelfActivationNotPermitted extends DomainException
{
    public static function forContract(string $contractId): self
    {
        return new self(sprintf('Contract [%s] cannot be activated by its creator (§24 / §228; ADR-176 D-C).', $contractId));
    }

    public function errorCode(): string
    {
        return 'contract_self_activation_not_permitted';
    }
}
