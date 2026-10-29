<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

final readonly class StaffNotificationContactDto
{
    public function __construct(
        public string $staffId,
        public ?string $phone,
        public ?string $email,
        public ?string $userId = null,
    ) {}
}
