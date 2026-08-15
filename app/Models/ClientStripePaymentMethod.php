<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id', 'client_company_id', 'client_stripe_customer_id', 'stripe_payment_method_id',
    'type', 'brand', 'last4', 'exp_month', 'exp_year', 'is_default', 'metadata',
])]
#[Hidden(['id', 'workspace_id', 'client_company_id', 'client_stripe_customer_id', 'stripe_payment_method_id', 'metadata'])]
class ClientStripePaymentMethod extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return [
            'exp_month' => 'integer',
            'exp_year' => 'integer',
            'is_default' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<ClientStripeCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(ClientStripeCustomer::class, 'client_stripe_customer_id');
    }

    /** @return BelongsTo<ClientCompany, $this> */
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }
}
