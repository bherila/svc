<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id', 'client_company_id', 'stripe_customer_id', 'metadata',
    'default_payment_method_event_created_at', 'default_payment_method_event_id',
])]
#[Hidden([
    'id', 'workspace_id', 'client_company_id', 'stripe_customer_id', 'metadata',
    'default_payment_method_event_created_at', 'default_payment_method_event_id',
])]
class ClientStripeCustomer extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'default_payment_method_event_created_at' => 'integer',
        ];
    }

    /** @return BelongsTo<ClientCompany, $this> */
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    /** @return HasMany<ClientStripePaymentMethod, $this> */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(ClientStripePaymentMethod::class);
    }
}
