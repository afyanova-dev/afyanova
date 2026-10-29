<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\Model\Allergy;
use App\Domains\Clinical\Domain\ValueObject\AllergySubstance;
use App\Domains\Clinical\Domain\ValueObject\AllergyVerificationStatus;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Record one immune-mediated-hypersensitivity assertion on a patient's allergy
 * safety record (12-clinical-domain.md §30–§33, §75; ADR-131, ADR-132,
 * ADR-135, ADR-141 D-A … D-V).
 *
 * `$allergyId` is client-minted (a UUIDv7) so this `Auditable` command names
 * its own target before the row exists (the `RecordDiagnosis` / `AddProblem`
 * precedent) and repeat delivery is idempotent. `$actorId` is the authenticated
 * recorder. `tenant_id` is resolved from `TenantContext`; `facility_id` is
 * taken from the ENCOUNTER context by the handler (ADR-141 D-B), never from
 * this command.
 *
 * `$substance` is the validated {@see AllergySubstance} value object —
 * `substance_name` required, `substance_code` optional (open string),
 * `substance_code_system` required iff a code is present; nothing is validated
 * against any terminology (ADR-141 D-E). `$category` is a REQUIRED open coded
 * string (documented `DRUG | FOOD | ENVIRONMENT | BIOLOGIC | OTHER`,
 * behaviour-neutral — ADR-141 D-F). `$reactionDescription` / `$severity` are
 * inline and optional (ADR-141 D-G).
 *
 * `$verificationStatus` is `SUSPECTED` (default) or `CONFIRMED` — an allergy is
 * NEVER recorded as `REFUTED` (ADR-141 D-K). `$authoredContext` is `CLINICIAN`
 * (default) or `NURSING` — set-membership-validated only, never an
 * authorization input, no `IdentityApi` (ADR-141 D-I).
 *
 * `$onsetDate` (optional, `Y-m-d`) is when the allergy / first reaction began —
 * nullable and later mutable; it may be in the past without a `reason` but
 * never in the future (ADR-141 D-H). `$occurredAt` is the clinical time the
 * allergy was entered on the record (ISO-8601, optional — defaults to server
 * `now()`); never in the future. When it is more than
 * {@see BACKDATE_REASON_THRESHOLD_HOURS} hours earlier than now, `$reason` is
 * REQUIRED (ADR-132) — it is written to the AUDIT row only, never to a `clin_`
 * column.
 *
 * Class-level (create) authorization: the bus checks `AllergyPolicy::record`.
 * The handler adds the ADR-141 per-encounter-facility refinement.
 */
final readonly class RecordAllergy implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, backdated documentation must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $allergyId,
        public string $actorId,
        public string $patientId,
        public string $encounterId,
        public string $category,
        public AllergySubstance $substance,
        public ?string $reactionDescription = null,
        public ?string $severity = null,
        public string $verificationStatus = AllergyVerificationStatus::SUSPECTED,
        public string $authoredContext = Allergy::AUTHORED_CONTEXT_DEFAULT,
        public ?string $onsetDate = null,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['allergyId' => $allergyId, 'actorId' => $actorId, 'patientId' => $patientId, 'encounterId' => $encounterId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordAllergy::\${$name} must be a UUID (IC §3).");
            }
        }

        if (trim($category) === '') {
            throw new InvalidArgumentException('RecordAllergy::$category must not be blank.');
        }

        AllergyVerificationStatus::assertRecordable($verificationStatus);

        if (! in_array($authoredContext, Allergy::AUTHORED_CONTEXTS, true)) {
            throw new InvalidArgumentException('RecordAllergy::$authoredContext must be CLINICIAN or NURSING.');
        }

        if ($onsetDate !== null) {
            try {
                $onset = CarbonImmutable::parse($onsetDate);
            } catch (Throwable) {
                throw new InvalidArgumentException('RecordAllergy::$onsetDate must be an ISO-8601 date or null.');
            }

            if ($onset->startOfDay()->greaterThan(CarbonImmutable::now()->startOfDay())) {
                throw new InvalidArgumentException('RecordAllergy::$onsetDate must not be in the future.');
            }
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('RecordAllergy::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('RecordAllergy::$occurredAt must not be in the future (12 §96, ADR-132).');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'RecordAllergy::$reason is required when $occurredAt is more than %d hours in the past.',
                    self::BACKDATE_REASON_THRESHOLD_HOURS,
                ));
            }
        }
    }

    public function policyAbility(): string
    {
        return 'record';
    }

    public function subjectId(): ?string
    {
        return null; // create — class-level check (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.allergies.recorded',
            actorId: $this->actorId,
            targetType: 'allergy',
            targetId: $this->allergyId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
