<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\BillingService;
use App\Domains\Billing\Domain\ValueObject\BillingServiceCategory;
use App\Domains\Billing\Domain\ValueObject\BillingServiceStatus;
use App\Shared\Bus\ProtectedQuery;
use InvalidArgumentException;

/**
 * The tenant's billing-service catalog, optionally filtered by category /
 * status (20-billing-and-payments-domain.md §236; ADR-188 D-D). `view` gates on
 * the coarse `billing.access`; the catalog is tenant-wide (no facility scope).
 */
#[ProtectedQuery(ability: 'view', subjectType: BillingService::class)]
final readonly class SearchBillingServices
{
    public ?string $category;

    public ?string $status;

    public function __construct(?string $category = null, ?string $status = null)
    {
        if ($category !== null && ! in_array(strtoupper(trim($category)), BillingServiceCategory::ALL, true)) {
            throw new InvalidArgumentException('SearchBillingServices::$category must be a valid category or null.');
        }
        if ($status !== null && ! BillingServiceStatus::isValid(strtoupper(trim($status)))) {
            throw new InvalidArgumentException('SearchBillingServices::$status must be ACTIVE | INACTIVE or null.');
        }

        $this->category = $category !== null ? strtoupper(trim($category)) : null;
        $this->status = $status !== null ? strtoupper(trim($status)) : null;
    }
}
