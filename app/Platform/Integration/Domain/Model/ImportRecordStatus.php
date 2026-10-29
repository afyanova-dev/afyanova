<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Model;

use App\Platform\Integration\Domain\Enum\DispatchStatus;
use App\Platform\Integration\Domain\Enum\ImportDomainOutcome;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $file_import_id
 * @property int $record_index
 * @property string|null $record_ref
 * @property string|null $translated_to
 * @property DispatchStatus $dispatch_status
 * @property ImportDomainOutcome $domain_outcome
 * @property string|null $outcome_reason
 */
final class ImportRecordStatus extends Model
{
    use HasUuids;

    protected $table = 'int_import_record_statuses';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'record_index' => 'integer',
            'dispatch_status' => DispatchStatus::class,
            'domain_outcome' => ImportDomainOutcome::class,
        ];
    }

    /**
     * @return BelongsTo<FileImport, $this>
     */
    public function fileImport(): BelongsTo
    {
        return $this->belongsTo(FileImport::class, 'file_import_id');
    }

    public function markCommitted(?string $reason = null): void
    {
        $this->dispatch_status = DispatchStatus::COMMITTED;
        $this->domain_outcome = ImportDomainOutcome::COMMITTED;
        $this->outcome_reason = $reason;
    }

    public function markRejected(string $reason): void
    {
        $this->dispatch_status = DispatchStatus::REJECTED;
        $this->domain_outcome = ImportDomainOutcome::REJECTED;
        $this->outcome_reason = $reason;
    }
}
