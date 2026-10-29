<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Repository;

use App\Domains\Procurement\Domain\ApprovalPolicyRepository;
use App\Domains\Procurement\Domain\Model\ApprovalPolicy;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

final class EloquentApprovalPolicyRepository extends EloquentRepository implements ApprovalPolicyRepository
{
    protected function model(): string
    {
        return ApprovalPolicy::class;
    }

    public function getById(string $id): ApprovalPolicy
    {
        return ApprovalPolicy::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(ApprovalPolicy::class, $id);
    }

    public function getByIdForUpdate(string $id): ApprovalPolicy
    {
        return ApprovalPolicy::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(ApprovalPolicy::class, $id);
    }

    public function findFor(string $facilityId, ?string $departmentRef): ?ApprovalPolicy
    {
        $query = ApprovalPolicy::query()->where('facility_id', $facilityId);

        $query = $departmentRef === null
            ? $query->whereNull('department_ref')
            : $query->where('department_ref', $departmentRef);

        return $query->first();
    }

    public function listForFacility(string $facilityId): array
    {
        $rows = [];

        foreach (ApprovalPolicy::query()
            ->where('facility_id', $facilityId)
            ->orderByRaw('department_ref is not null')   // facility-wide row first
            ->orderBy('department_ref')
            ->get() as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function delete(ApprovalPolicy $policy): void
    {
        $policy->delete();
    }
}
