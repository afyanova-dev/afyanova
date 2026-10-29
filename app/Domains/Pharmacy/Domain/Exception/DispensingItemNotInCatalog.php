<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

final class DispensingItemNotInCatalog extends DomainException
{
    public function __construct()
    {
        parent::__construct('Select an active medication catalog item before dispensing.');
    }

    public function errorCode(): string
    {
        return 'dispensing_item_not_in_catalog';
    }
}
