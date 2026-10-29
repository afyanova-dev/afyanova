<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\Authorization;
use App\Shared\Bus\ProtectedQuery;

/**
 * Retrieve all pending (REQUESTED) pre-authorizations — the §182 aging / approver worklist.
 * `view` gates on the coarse `insurance.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Authorization::class)]
final readonly class GetPendingAuthorizations
{
    public function __construct() {}
}
