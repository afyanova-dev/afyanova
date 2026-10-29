<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Model;

use App\Domains\HR\Contracts\Event\PersonCreated;
use App\Domains\HR\Domain\ValueObject\Gender;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Human employee demographic identity (25-hr-and-workforce-domain.md §2, §6; ADR-206).
 *
 * Distinct from Staff: Person represents the biological human identity.
 *
 * @domain      HR
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not-applicable
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property CarbonImmutable $date_of_birth
 * @property Gender $gender
 * @property string|null $national_id
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $physical_address
 * @property string|null $photo_document_id
 * @property string|null $next_of_kin_name
 * @property string|null $next_of_kin_relationship
 * @property string|null $next_of_kin_phone
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Person extends AggregateRoot
{
    protected $table = 'hr_persons';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
        'gender',
        'national_id',
        'phone',
        'email',
        'physical_address',
        'photo_document_id',
        'next_of_kin_name',
        'next_of_kin_relationship',
        'next_of_kin_phone',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'date_of_birth' => 'immutable_date',
        'gender' => Gender::class,
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function create(
        string $id,
        string $tenantId,
        string $firstName,
        ?string $middleName,
        string $lastName,
        CarbonImmutable $dateOfBirth,
        Gender $gender,
        ?string $nationalId = null,
        ?string $phone = null,
        ?string $email = null,
        ?string $physicalAddress = null,
        ?string $nextOfKinName = null,
        ?string $nextOfKinRelationship = null,
        ?string $nextOfKinPhone = null,
        ?string $photoDocumentId = null,
    ): self {
        $person = new self;
        $person->id = $id;
        $person->tenant_id = $tenantId;
        $person->first_name = trim($firstName);
        $person->middle_name = $middleName !== null ? trim($middleName) : null;
        $person->last_name = trim($lastName);
        $person->date_of_birth = $dateOfBirth;
        $person->gender = $gender;
        $person->national_id = $nationalId !== null ? trim($nationalId) : null;
        $person->phone = $phone !== null ? trim($phone) : null;
        $person->email = $email !== null ? trim($email) : null;
        $person->physical_address = $physicalAddress !== null ? trim($physicalAddress) : null;
        $person->photo_document_id = $photoDocumentId;
        $person->next_of_kin_name = $nextOfKinName !== null ? trim($nextOfKinName) : null;
        $person->next_of_kin_relationship = $nextOfKinRelationship !== null ? trim($nextOfKinRelationship) : null;
        $person->next_of_kin_phone = $nextOfKinPhone !== null ? trim($nextOfKinPhone) : null;

        $person->recordThat(new PersonCreated(
            eventId: (string) Str::uuid(),
            tenantId: $tenantId,
            personId: $id,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));

        return $person;
    }

    public function updateDetails(
        string $firstName,
        ?string $middleName,
        string $lastName,
        CarbonImmutable $dateOfBirth,
        Gender $gender,
        ?string $nationalId = null,
        ?string $phone = null,
        ?string $email = null,
        ?string $physicalAddress = null,
        ?string $nextOfKinName = null,
        ?string $nextOfKinRelationship = null,
        ?string $nextOfKinPhone = null,
    ): void {
        $this->first_name = trim($firstName);
        $this->middle_name = $middleName !== null ? trim($middleName) : null;
        $this->last_name = trim($lastName);
        $this->date_of_birth = $dateOfBirth;
        $this->gender = $gender;
        $this->national_id = $nationalId !== null ? trim($nationalId) : null;
        $this->phone = $phone !== null ? trim($phone) : null;
        $this->email = $email !== null ? trim($email) : null;
        $this->physical_address = $physicalAddress !== null ? trim($physicalAddress) : null;
        $this->next_of_kin_name = $nextOfKinName !== null ? trim($nextOfKinName) : null;
        $this->next_of_kin_relationship = $nextOfKinRelationship !== null ? trim($nextOfKinRelationship) : null;
        $this->next_of_kin_phone = $nextOfKinPhone !== null ? trim($nextOfKinPhone) : null;
    }

    public function fullName(): string
    {
        if ($this->middle_name !== null && $this->middle_name !== '') {
            return sprintf('%s %s %s', $this->first_name, $this->middle_name, $this->last_name);
        }

        return sprintf('%s %s', $this->first_name, $this->last_name);
    }

    public function updatePhoto(string $photoDocumentId): void
    {
        $this->photo_document_id = $photoDocumentId;
    }
}
