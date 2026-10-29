<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Database\Factories\RfqInvitationFactory;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One supplier invited to quote on an RFQ (19-procurement-domain.md §30, §33;
 * ADR-173 D-B).
 *
 * Plain Eloquent child — NOT an AggregateRoot. Written by `CreateRfqHandler`
 * (the invited set is fixed at RFQ create in Proc.2a — cancel + recreate to
 * change it). A quotation is accepted ONLY from an invited supplier
 * (`SupplierNotInvited`). `version` shape / conformance-only — stays 0.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $rfq_id
 * @property string $supplier_id
 * @property CarbonImmutable $invited_at
 * @property int $version
 */
class RfqInvitation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RfqInvitationFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    protected $table = 'pcr_rfq_invitations';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'rfq_id', 'supplier_id', 'invited_at',
        'version',
    ];

    protected static function newFactory(): RfqInvitationFactory
    {
        return RfqInvitationFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invited_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
