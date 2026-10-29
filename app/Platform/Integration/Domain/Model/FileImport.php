<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Model;

use App\Platform\Integration\Domain\Enum\FileImportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $connector_id
 * @property string|null $external_ref
 * @property string $filename
 * @property int $byte_size
 * @property int $record_count
 * @property int $processed_count
 * @property int $committed_count
 * @property int $rejected_count
 * @property FileImportStatus $status
 * @property string|null $failure_reason
 */
final class FileImport extends Model
{
    use HasUuids;

    protected $table = 'int_file_imports';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'record_count' => 'integer',
            'processed_count' => 'integer',
            'committed_count' => 'integer',
            'rejected_count' => 'integer',
            'status' => FileImportStatus::class,
        ];
    }

    /**
     * @return BelongsTo<IntegrationConnector, $this>
     */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnector::class, 'connector_id');
    }

    /**
     * @return HasMany<ImportRecordStatus, $this>
     */
    public function recordStatuses(): HasMany
    {
        return $this->hasMany(ImportRecordStatus::class, 'file_import_id');
    }

    public function incrementProcessed(bool $committed): void
    {
        $this->processed_count++;
        if ($committed) {
            $this->committed_count++;
        } else {
            $this->rejected_count++;
        }

        if ($this->processed_count >= $this->record_count && $this->record_count > 0) {
            $this->status = ($this->rejected_count === 0)
                ? FileImportStatus::RECONCILED
                : FileImportStatus::PARTIAL;
        }
    }

    public function markFailed(string $reason): void
    {
        $this->status = FileImportStatus::FAILED;
        $this->failure_reason = $reason;
    }
}
