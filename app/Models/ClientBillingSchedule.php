<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $next_run_on
 * @property array<int, array<string, mixed>> $line_template
 * @property string $cadence
 * @property bool $is_active
 */
#[Fillable([
    'workspace_id', 'client_company_id', 'client_agreement_id', 'cadence', 'anchor_month',
    'anchor_day', 'next_run_on', 'due_days', 'currency', 'is_active', 'line_template',
])]
#[Hidden(['id', 'workspace_id', 'client_company_id', 'client_agreement_id'])]
class ClientBillingSchedule extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected function casts(): array
    {
        return [
            'lock_version' => 'integer',
            'next_run_on' => DateOnly::class,
            'anchor_month' => 'integer',
            'anchor_day' => 'integer',
            'due_days' => 'integer',
            'is_active' => 'boolean',
            'line_template' => 'array',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ClientCompany, $this> */
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    /** @return BelongsTo<ClientAgreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(ClientAgreement::class, 'client_agreement_id');
    }
}
