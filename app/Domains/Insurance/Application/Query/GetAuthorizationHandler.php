<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\AuthorizationSummary;
use App\Domains\Insurance\Domain\AuthorizationReader;

final class GetAuthorizationHandler
{
    public function __construct(private readonly AuthorizationReader $reader) {}

    public function __invoke(GetAuthorization $query): AuthorizationSummary
    {
        return $this->reader->summary($query->authorizationId);
    }
}
