<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One workspace's connected account at one processor.
 *
 * The OAuth tokens are encrypted at rest and hidden from every serialization:
 * they are read only through {@see accessToken()} and {@see refreshToken()} by
 * the adapters that call the processor, and never reach a log, the API or the
 * browser. `__debugInfo` keeps them out of dumps as well.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $processor
 * @property string $external_account_id
 * @property string|null $display_name
 * @property string|null $environment
 * @property string $origin
 * @property int|null $reuses_connection_id
 * @property string $endpoint_token
 * @property string|null $secret
 * @property string|null $webhook_secret
 * @property string|null $secret_hint
 * @property string|null $webhook_endpoint_id
 * @property string|null $webhook_check_status
 * @property list<string>|null $webhook_check_missing
 * @property Carbon|null $webhook_checked_at
 * @property string|null $location_id
 * @property string|null $publishable_key
 * @property int|null $connected_by_user_id
 * @property Carbon $connected_at
 * @property Carbon|null $revoked_at
 * @property string|null $active_slot
 */
#[Fillable([
    'workspace_id', 'processor', 'external_account_id', 'display_name', 'environment', 'origin',
    'reuses_connection_id', 'endpoint_token', 'secret', 'webhook_secret', 'secret_hint',
    'webhook_endpoint_id', 'webhook_check_status', 'webhook_check_missing', 'webhook_checked_at',
    'location_id', 'publishable_key', 'connected_by_user_id', 'connected_at', 'revoked_at',
    'revoked_by_user_id', 'active_slot',
])]
#[Hidden([
    'id', 'workspace_id', 'secret', 'webhook_secret', 'endpoint_token', 'webhook_endpoint_id',
    'reuses_connection_id', 'connected_by_user_id', 'revoked_by_user_id', 'active_slot',
])]
class WorkspacePaymentConnection extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'webhook_check_missing' => 'array',
            'webhook_checked_at' => 'datetime',
            'connected_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function processorEnum(): PaymentProcessor
    {
        return PaymentProcessor::from($this->processor);
    }

    public function originEnum(): ConnectionOrigin
    {
        return ConnectionOrigin::from($this->origin);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function isReused(): bool
    {
        return $this->reuses_connection_id !== null;
    }

    /** The stored key or token, for the adapter that calls the processor only. */
    public function secret(): ?string
    {
        $value = $this->getAttribute('secret');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The secret that signs this connection's webhooks, for signature checks only. */
    public function webhookSecret(): ?string
    {
        $value = $this->getAttribute('webhook_secret');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** `rk_live_...a1b2`: the kind of key and its last four characters, never more. */
    public static function hint(string $secret): string
    {
        $prefix = '';
        if (preg_match('/^([a-z]{2,3}_(?:live|test)_|sq0[a-z]{3}-)/D', $secret, $match) === 1) {
            $prefix = $match[1];
        }

        return $prefix.'…'.substr($secret, -4);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'public_id' => $this->public_id,
            'workspace_id' => $this->workspace_id,
            'processor' => $this->processor,
            'external_account_id' => $this->external_account_id,
            'secret' => $this->getRawOriginal('secret') === null ? null : '[redacted]',
            'webhook_secret' => $this->getRawOriginal('webhook_secret') === null ? null : '[redacted]',
        ];
    }
}
