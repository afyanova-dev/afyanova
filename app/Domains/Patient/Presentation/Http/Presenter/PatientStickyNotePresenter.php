<?php

declare(strict_types=1);

namespace App\Domains\Patient\Presentation\Http\Presenter;

use App\Domains\Patient\Domain\Model\PatientStickyNote;
use App\Domains\Patient\Domain\PatientStickyNoteRepository;
use App\Models\User;
use App\Shared\Domain\NotFound;

final readonly class PatientStickyNotePresenter
{
    public function __construct(private PatientStickyNoteRepository $notes) {}

    /**
     * @return array{id: string, body: string, author: string|null, updatedBy: string|null, createdAt: string|null, updatedAt: string|null}|null
     */
    public function forPatient(string $patientId): ?array
    {
        $note = $this->notes->findActiveForPatient($patientId);

        return $note === null ? null : $this->serialize($note);
    }

    /**
     * @return array{id: string, body: string, author: string|null, updatedBy: string|null, createdAt: string|null, updatedAt: string|null}
     */
    public function byId(string $noteId): array
    {
        try {
            return $this->serialize($this->notes->getById($noteId));
        } catch (NotFound) {
            abort(404, 'Sticky note not found.');
        }
    }

    public function assertBelongsToPatient(string $patientId, string $noteId): void
    {
        try {
            $note = $this->notes->getById($noteId);
        } catch (NotFound) {
            abort(404, 'Sticky note not found.');
        }

        abort_unless((string) $note->patient_id === $patientId && $note->archived_at === null, 404);
    }

    /**
     * @return array{id: string, body: string, author: string|null, updatedBy: string|null, createdAt: string|null, updatedAt: string|null}
     */
    private function serialize(PatientStickyNote $note): array
    {
        $userIds = array_values(array_filter([
            (string) $note->recorded_by_user_id,
            $note->updated_by_user_id === null ? null : (string) $note->updated_by_user_id,
        ]));
        $names = User::query()->whereKey($userIds)->pluck('name', 'id');

        return [
            'id' => (string) $note->id,
            'body' => (string) $note->body,
            'author' => $names->get((string) $note->recorded_by_user_id),
            'updatedBy' => $note->updated_by_user_id === null
                ? null
                : $names->get((string) $note->updated_by_user_id),
            'createdAt' => $note->created_at?->toIso8601String(),
            'updatedAt' => $note->updated_at?->toIso8601String(),
        ];
    }
}
