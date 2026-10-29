<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Model;

use App\Domains\Radiology\Database\Factories\ImagingServiceFactory;
use App\Shared\Domain\AggregateRoot;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use InvalidArgumentException;

/**
 * One imaging service catalog entry — a patient-facing imaging examination
 * offering (Chest X-Ray, CT Abdomen …), performed on a modality
 * (15-radiology-domain.md §11–§12, §185, §187; ADR-165 D-D).
 *
 * Tenant-scoped, mutable, integer-versioned reference data — NOT the ADR-132
 * clinical-record shape (the CP.1 / Lab.1 catalog precedent). Authoring is the
 * `radiology:define-imaging-service` console command ONLY (ADR-165 D-D).
 *
 * `body_site` / `default_laterality` / `contrast_requirement` are open-coded,
 * normalised uppercase, DOCUMENTATION-ONLY in Rad.1. `preparation_text` is free
 * text, ROW-ONLY (ADR-136). `referral_only` marks a service offered but not
 * performed internally — Rad.1 blocks internal ordering of it.
 *
 * NOT immutable / NOT superseding: {@see revise()} bumps `version` in place.
 *
 * @domain      Radiology
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    none — tenant-wide reference data (ADR-165 D-D)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $modality_id
 * @property string|null $body_site
 * @property string|null $default_laterality
 * @property string $contrast_requirement
 * @property string|null $preparation_text
 * @property int|null $duration_minutes
 * @property int|null $turnaround_target_minutes
 * @property string|null $required_capability_code
 * @property bool $referral_only
 * @property string $availability_status
 * @property bool $active
 * @property int $version
 */
final class ImagingService extends AggregateRoot
{
    /** @use HasFactory<ImagingServiceFactory> */
    use HasFactory;

    public const CONTRAST_NONE = 'NONE';

    public const CONTRAST_OPTIONAL = 'OPTIONAL';

    public const CONTRAST_REQUIRED = 'REQUIRED';

    public const AVAILABILITY_AVAILABLE = 'AVAILABLE';

    public const AVAILABILITY_DISABLED = 'DISABLED';

    private const MAX_CODE_LENGTH = 100;

    private const MAX_NAME_LENGTH = 200;

    protected $table = 'rad_imaging_services';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'code', 'name', 'modality_id',
        'body_site', 'default_laterality', 'contrast_requirement', 'preparation_text',
        'duration_minutes', 'turnaround_target_minutes',
        'required_capability_code', 'referral_only', 'availability_status',
        'active', 'version',
    ];

    protected static function newFactory(): ImagingServiceFactory
    {
        return ImagingServiceFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'turnaround_target_minutes' => 'integer',
            'referral_only' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * Define a new catalog entry (ADR-165 D-D — the console write path).
     * `$id` is client-minted (a UUIDv7). `version` starts at 1.
     */
    public static function define(
        string $id,
        string $tenantId,
        string $code,
        string $name,
        string $modalityId,
        ?string $bodySite = null,
        ?string $defaultLaterality = null,
        string $contrastRequirement = self::CONTRAST_NONE,
        ?string $preparationText = null,
        ?int $durationMinutes = null,
        ?int $turnaroundTargetMinutes = null,
        ?string $requiredCapabilityCode = null,
        bool $referralOnly = false,
        string $availabilityStatus = self::AVAILABILITY_AVAILABLE,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'code' => self::assertCode($code),
            'name' => self::assertName($name),
            'modality_id' => $modalityId,
            'body_site' => self::normaliseCode($bodySite),
            'default_laterality' => self::normaliseCode($defaultLaterality),
            'contrast_requirement' => self::normaliseCode($contrastRequirement) ?? self::CONTRAST_NONE,
            'preparation_text' => self::blankToNull($preparationText),
            'duration_minutes' => $durationMinutes,
            'turnaround_target_minutes' => $turnaroundTargetMinutes,
            'required_capability_code' => self::blankToNull($requiredCapabilityCode),
            'referral_only' => $referralOnly,
            'availability_status' => self::normaliseCode($availabilityStatus) ?? self::AVAILABILITY_AVAILABLE,
            'active' => true,
            'version' => 1,
        ]);
    }

    /**
     * Update this service in place (ADR-165 D-D — the console write path ONLY).
     * Bumps `version`. `null` for a parameter leaves the current value unchanged.
     */
    public function revise(
        ?string $name = null,
        ?string $modalityId = null,
        ?string $bodySite = null,
        ?string $defaultLaterality = null,
        ?string $contrastRequirement = null,
        ?string $preparationText = null,
        ?int $durationMinutes = null,
        ?int $turnaroundTargetMinutes = null,
        ?string $requiredCapabilityCode = null,
        ?bool $referralOnly = null,
        ?string $availabilityStatus = null,
        ?bool $active = null,
    ): void {
        if ($name !== null) {
            $this->name = self::assertName($name);
        }
        if ($modalityId !== null) {
            $this->modality_id = $modalityId;
        }
        if ($bodySite !== null) {
            $this->body_site = self::normaliseCode($bodySite);
        }
        if ($defaultLaterality !== null) {
            $this->default_laterality = self::normaliseCode($defaultLaterality);
        }
        if ($contrastRequirement !== null) {
            $this->contrast_requirement = self::normaliseCode($contrastRequirement) ?? self::CONTRAST_NONE;
        }
        if ($preparationText !== null) {
            $this->preparation_text = self::blankToNull($preparationText);
        }
        if ($durationMinutes !== null) {
            $this->duration_minutes = $durationMinutes;
        }
        if ($turnaroundTargetMinutes !== null) {
            $this->turnaround_target_minutes = $turnaroundTargetMinutes;
        }
        if ($requiredCapabilityCode !== null) {
            $this->required_capability_code = self::blankToNull($requiredCapabilityCode);
        }
        if ($referralOnly !== null) {
            $this->referral_only = $referralOnly;
        }
        if ($availabilityStatus !== null) {
            $this->availability_status = self::normaliseCode($availabilityStatus) ?? self::AVAILABILITY_AVAILABLE;
        }
        if ($active !== null) {
            $this->active = $active;
        }

        $this->version = (int) $this->version + 1;
    }

    /** Can a new order line be placed against this service? (§18–§20, §84, §137). */
    public function isOrderable(): bool
    {
        return (bool) $this->active
            && ! (bool) $this->referral_only
            && $this->availability_status !== self::AVAILABILITY_DISABLED;
    }

    private static function assertCode(string $code): string
    {
        $trimmed = trim($code);

        if ($trimmed === '') {
            throw new InvalidArgumentException('ImagingService::$code must not be blank (ADR-165 D-D).');
        }

        if (mb_strlen($trimmed) > self::MAX_CODE_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'ImagingService::$code must be at most %d characters (ADR-165 D-D).',
                self::MAX_CODE_LENGTH,
            ));
        }

        return strtoupper($trimmed);
    }

    private static function assertName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new InvalidArgumentException('ImagingService::$name must not be blank (ADR-165 D-D).');
        }

        if (mb_strlen($trimmed) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'ImagingService::$name must be at most %d characters (ADR-165 D-D).',
                self::MAX_NAME_LENGTH,
            ));
        }

        return $trimmed;
    }

    private static function normaliseCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtoupper(trim($value));

        return $trimmed === '' ? null : $trimmed;
    }

    private static function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
