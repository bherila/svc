<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Services\Square\Mirror\SquareMirrorRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Square customer as the last sync saw it (#438): the allow-listed fields
 * only. Read-only mirror data, written only by
 * {@see SquareMirrorRepository}.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $square_customer_id
 * @property int|null $square_version
 * @property string|null $given_name
 * @property string|null $family_name
 * @property string|null $company_name
 * @property string|null $email_address
 * @property string|null $phone_number
 * @property string|null $reference_id
 * @property CarbonImmutable|null $square_created_at
 * @property CarbonImmutable|null $square_updated_at
 * @property string $fingerprint
 * @property CarbonImmutable $synced_at
 * @property CarbonImmutable $last_seen_at
 * @property int|null $last_sync_run_id
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable([
    'workspace_id', 'square_customer_id', 'square_version', 'given_name', 'family_name', 'company_name', 'email_address',
    'phone_number', 'reference_id', 'square_created_at', 'square_updated_at', 'fingerprint', 'synced_at', 'last_seen_at', 'last_sync_run_id',
])]
#[Hidden(['id', 'workspace_id', 'fingerprint', 'deleted_at', 'last_sync_run_id'])]
class SquareCustomerSnapshot extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'square_version' => 'integer',
            'square_created_at' => 'immutable_datetime',
            'square_updated_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'last_sync_run_id' => 'integer',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /** The name a person would recognise: the company, else the person. */
    public function displayName(): ?string
    {
        $person = trim(($this->given_name ?? '').' '.($this->family_name ?? ''));

        return $this->company_name ?? ($person !== '' ? $person : null);
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
