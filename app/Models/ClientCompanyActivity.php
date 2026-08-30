<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_company_id
 * @property int|null $actor_user_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_public_id
 * @property string|null $deduplication_key
 * @property array<string, mixed>|null $payload
 */
#[Fillable([
    'workspace_id', 'client_company_id', 'actor_user_id', 'action',
    'subject_type', 'external_subject_id', 'subject_public_id', 'deduplication_key', 'payload',
])]
#[Hidden(['id', 'workspace_id', 'client_company_id', 'actor_user_id', 'external_subject_id', 'deduplication_key'])]
class ClientCompanyActivity extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected $table = 'client_company_activity';

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /** @return BelongsTo<ClientCompany, $this> */
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
