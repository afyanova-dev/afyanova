<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Model;

use App\Domains\Scheduling\Contracts\Event\AppointmentTypeDefined;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Appointment type configuration aggregate root (22-scheduling-and-appointments-domain.md §7, §9).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $facility_id
 * @property string $code
 * @property string $name
 * @property int $duration_minutes
 * @property int $buffer_before_minutes
 * @property int $buffer_after_minutes
 * @property bool $is_virtual
 * @property bool $allow_patient_booking
 * @property bool $is_active
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class AppointmentType extends AggregateRoot
{
    protected $table = 'sch_appointment_types';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'duration_minutes',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'is_virtual',
        'allow_patient_booking',
        'is_active',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'duration_minutes' => 'integer',
        'buffer_before_minutes' => 'integer',
        'buffer_after_minutes' => 'integer',
        'is_virtual' => 'boolean',
        'allow_patient_booking' => 'boolean',
        'is_active' => 'boolean',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function define(
        string $tenantId,
        string $code,
        string $name,
        int $durationMinutes = 30,
        int $bufferBeforeMinutes = 0,
        int $bufferAfterMinutes = 0,
        bool $isVirtual = false,
        bool $allowPatientBooking = true,
        bool $isActive = true,
        ?string $facilityId = null,
        ?string $appointmentTypeId = null,
    ): self {
        $id = $appointmentTypeId ?? (string) Str::uuid();

        $type = new self([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'code' => trim($code),
            'name' => trim($name),
            'duration_minutes' => $durationMinutes,
            'buffer_before_minutes' => $bufferBeforeMinutes,
            'buffer_after_minutes' => $bufferAfterMinutes,
            'is_virtual' => $isVirtual,
            'allow_patient_booking' => $allowPatientBooking,
            'is_active' => $isActive,
            'version' => 0,
        ]);

        $type->id = $id;

        $type->recordThat(new AppointmentTypeDefined(
            eventId: (string) Str::uuid(),
            tenantId: $tenantId,
            appointmentTypeId: $id,
            facilityId: $facilityId,
            code: $type->code,
            name: $type->name,
            durationMinutes: $durationMinutes,
            bufferBeforeMinutes: $bufferBeforeMinutes,
            bufferAfterMinutes: $bufferAfterMinutes,
            isVirtual: $isVirtual,
            allowPatientBooking: $allowPatientBooking,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));

        return $type;
    }
}
