<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Database\Factories;

use App\Domains\Encounter\Domain\Model\EncounterRelationship;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EncounterRelationship>
 */
class EncounterRelationshipFactory extends Factory
{
    protected $model = EncounterRelationship::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'related_encounter_id' => (string) Str::uuid7(),
            'relationship_type' => 'REFERRED_FROM',
            'created_by_user_id' => (string) Str::uuid7(),
            'created_at' => now(),
            'version' => 0,
        ];
    }
}
