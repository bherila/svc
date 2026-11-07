<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Support\Billing\Autopay\AutopayActorRole;
use App\Support\Billing\Autopay\AutopayConsentAction;
use App\Support\Billing\Autopay\AutopayConsentMethod;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a client company's autopay consent record (#636): a turn-on, a
 * turn-off or a change of limit, with who made it, in which role, when, the
 * limit, how consent was given, and the exact wording the client agreed to or
 * the attestation the provider entered. Append-only: a row is never updated or
 * deleted by the application.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_company_id
 * @property AutopayConsentAction $action
 * @property int|null $actor_user_id
 * @property string|null $actor_name
 * @property AutopayActorRole $actor_role
 * @property AutopayConsentMethod $method
 * @property int|null $limit_amount
 * @property string|null $currency
 * @property string|null $payment_method_label
 * @property string|null $wording
 * @property string|null $attestation
 * @property string|null $reason
 * @property bool $notice_sent whether the client's billing contacts were emailed about a provider turn-on
 * @property CarbonImmutable $occurred_at
 */
#[Fillable([
    'workspace_id', 'client_company_id', 'action', 'actor_user_id', 'actor_name', 'actor_role', 'method',
    'limit_amount', 'currency', 'payment_method_label', 'wording', 'attestation', 'reason', 'notice_sent', 'occurred_at',
])]
#[Hidden(['id', 'workspace_id', 'client_company_id', 'actor_user_id'])]
class ClientAutopayConsent extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'action' => AutopayConsentAction::class,
            'actor_role' => AutopayActorRole::class,
            'method' => AutopayConsentMethod::class,
            'limit_amount' => 'integer',
            'notice_sent' => 'boolean',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (self $consent): void {
            // The one write after creation is the flag saying the notice
            // email went out; it is set once, by the service that sent it.
            $changed = array_keys($consent->getDirty());
            if ($changed !== ['notice_sent']) {
                throw new DomainException('An autopay consent record is append-only.');
            }
        });
        static::deleting(static function (): void {
            throw new DomainException('An autopay consent record is append-only.');
        });
    }

    /** @return BelongsTo<ClientCompany, $this> */
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
