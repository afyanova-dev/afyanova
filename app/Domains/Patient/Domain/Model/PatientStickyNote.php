<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Model;

use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;

/**
 * Tenant-wide operational reminder attached to a patient identity, not an
 * encounter or clinical record.
 *
 * @domain    Patient
 *
 * @authority authoritative
 *
 * @tenant    required
 *
 * @facility  not applicable — patient identity data is tenant-scoped
 *
 * @property CarbonImmutable|null $archived_at
 */
final class PatientStickyNote extends AggregateRoot
{
    protected $table = 'pat_patient_sticky_notes';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'patient_id', 'body',
        'recorded_by_user_id', 'updated_by_user_id', 'archived_by_user_id', 'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'immutable_datetime',
        ];
    }

    public static function record(
        string $id,
        string $tenantId,
        string $patientId,
        string $body,
        string $actorId,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'patient_id' => $patientId,
            'body' => trim($body),
            'recorded_by_user_id' => $actorId,
        ]);
    }

    public function revise(string $body, string $actorId): void
    {
        if ($this->archived_at !== null) {
            throw new \LogicException('An archived patient sticky note cannot be edited.');
        }

        $this->body = trim($body);
        $this->updated_by_user_id = $actorId;
    }

    public function archive(string $actorId): void
    {
        if ($this->archived_at !== null) {
            throw new \LogicException('Patient sticky note is already archived.');
        }

        $this->archived_at = CarbonImmutable::now();
        $this->archived_by_user_id = $actorId;
    }
}
