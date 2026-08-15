<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $client_company_id
 * @property int $user_id
 * @property string $role
 */
#[Fillable(['public_id', 'client_company_id', 'user_id', 'role'])]
#[Hidden(['id', 'client_company_id', 'user_id'])]
class ClientCompanyMembership extends Pivot
{
    use HasPublicId;

    public $incrementing = true;

    protected $table = 'client_company_memberships';

    /** @return BelongsTo<ClientCompany, $this> */
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
