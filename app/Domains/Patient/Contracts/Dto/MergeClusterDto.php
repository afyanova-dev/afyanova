<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Dto;

/**
 * A bounded group of likely-duplicate MPI identities surfaced by the merge
 * review console (UI-SPECIFICATIONS §11.2.4). Owned by Patient; read within
 * the current tenant scope (RLS). Candidates are ACTIVE/INACTIVE only —
 * MERGED identities are never candidates (ADR-128 M-10).
 *
 * @param  list<MergeCandidateDto>  $candidates
 */
final readonly class MergeClusterDto
{
    public function __construct(
        public string $clusterId,
        /** Source of the collision: 'VERIFIED_IDENTIFIER' | 'NAME_AND_DOB' */
        public string $matchedOn,
        /** The shared folded signal (e.g. normalised identifier value or "name|dob"). */
        public string $matchedSignal,
        public array $candidates,
        public bool $moreClusters = false,
    ) {}
}