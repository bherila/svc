<?php

namespace App\Services\Credentials;

use App\Models\User;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use BWH\Auth\OAuth\Server\ProviderIdentityTokens;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Psr\Log\LoggerInterface;

/**
 * The one place SVC revokes a credential: a connection disconnecting itself
 * (`DELETE /api/v1/connections/{token}`), an operator revoking a named token
 * or every connection a person holds, and the deployment smoke ending its own.
 *
 * Agent (Passport) credentials are revoked, never deleted, in one transaction
 * on Passport's connection: the selected access-token rows are locked, their
 * refresh tokens are revoked before the access tokens themselves - a refresh
 * token outlives a purged access token and could otherwise mint a replacement -
 * and each access token goes through Passport's repository so revocation
 * listeners hear of it. Revocation is checked on every request, so a token
 * stops working at once rather than at its expiry.
 *
 * Every revocation is audited once it commits, by credential fingerprint and
 * the owner's public id; never a token, a token id or an email address.
 */
final readonly class CredentialRevoker
{
    public function __construct(
        private AccessTokenRepository $accessTokens,
        private LoggerInterface $log,
        private WorkspaceClock $clock,
        private AgentCredentialFence $fence,
    ) {}

    /**
     * @throws AmbiguousRevocation when more access tokens match than the selection allows
     */
    public function revoke(AgentCredentialSelection $selection, string $actor): AgentRevocation
    {
        $connection = Passport::token()->getConnection();
        // Raised first, so a grant that persists while this runs is caught too.
        $this->raiseFences($selection);

        return $connection->transaction(function () use ($selection, $actor, $connection): AgentRevocation {
            // Raised again once this commits, so the whole window follows the
            // revocation however long the transaction took.
            $connection->afterCommit(fn () => $this->raiseFences($selection));
            $ids = array_values($this->accessTokenQuery($selection)
                ->tap(Locks::forUpdate())
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all());
            if ($selection->atMost !== null && count($ids) > $selection->atMost) {
                throw new AmbiguousRevocation(count($ids));
            }

            // Refresh tokens first: revoking the access token alone would leave
            // the connection able to mint its replacement.
            $refreshTokens = $this->revokeRefreshTokens($selection, $ids);
            $authorizationCodes = $selection->scope === AgentCredentialScope::Connections
                ? $this->revokeAuthorizationCodes($selection)
                : 0;
            foreach ($ids as $id) {
                $this->accessTokens->revokeAccessToken($id);
            }

            $revocation = new AgentRevocation(CredentialFingerprint::all($ids), $refreshTokens, $authorizationCodes);
            $connection->afterCommit(fn () => $this->log->info('agent_token.revoked', array_filter([
                'actor' => $actor,
                'subject_id' => $selection->ownerPublicId,
                'selection' => $selection->scope->value,
                'name' => $selection->label,
                'client_id' => $selection->clientId,
                'revoked' => $revocation->accessTokens(),
                'refresh_tokens_revoked' => $refreshTokens,
                'authorization_codes_revoked' => $authorizationCodes,
                'credential_fingerprints' => $revocation->fingerprints,
            ], static fn (mixed $value): bool => $value !== null)));

            return $revocation;
        });
    }

    /**
     * Live refresh tokens no revocation by owner can reach: they record no
     * owner (issued before refresh tokens did, and missed by the backfill
     * because their access token was already purged) and have no access token
     * left to name one.
     */
    public function unattributedRefreshTokens(): int
    {
        return $this->unattributedRefreshTokenQuery()->count();
    }

    /**
     * Revoke every {@see unattributedRefreshTokens()} - whoever they belong
     * to, which is exactly what cannot be known - so a containment that must
     * not miss one can close them. Each such connection authorizes again.
     *
     * Only while OAuth issuance is off: no fence can name the owner of a
     * token nobody can attribute, so a refresh already in flight would
     * otherwise persist a live replacement after this revokes its parent.
     *
     * @throws RevocationRefused while OAuth issuance is on
     */
    public function revokeUnattributedRefreshTokens(string $actor): int
    {
        if (! self::unattributedRevocationAllowed()) {
            throw new RevocationRefused('Revoking unattributed refresh tokens needs OAuth issuance off first (AGENT_API_OAUTH_SERVER_ENABLED=false), so no refresh can replace one meanwhile.');
        }
        $revoked = $this->unattributedRefreshTokenQuery()->update(['revoked' => true]);
        $this->log->info('agent_token.unattributed_refresh_tokens_revoked', ['actor' => $actor, 'revoked' => $revoked]);

        return $revoked;
    }

    /**
     * A person's connections fence the person and client (or every client).
     * One connection disconnecting itself fences only its own lineage: a
     * refresh of this token that passed its check may already have revoked it
     * (a refresh grant revokes the old access token before issuing the new
     * one), may be waiting on the lock, or may race a purge of the row, and
     * persists its replacement after this whichever it is. Keyed on the token
     * id, it needs no row, and another connection the same person holds with
     * the same client keeps refreshing.
     */
    private function raiseFences(AgentCredentialSelection $selection): void
    {
        if (! $selection->fence) {
            return;
        }
        match ($selection->scope) {
            AgentCredentialScope::Connections => $this->fence->raise($selection->ownerId, $selection->clientId),
            AgentCredentialScope::Token => $this->fence->raiseLineage((string) $selection->tokenId),
            AgentCredentialScope::Name => null,
        };
    }

    /** Whether {@see revokeUnattributedRefreshTokens()} may run: only with OAuth issuance (and so refresh) off. */
    public static function unattributedRevocationAllowed(): bool
    {
        return ! (bool) config('bherila-auth.oauth_server.enabled', false);
    }

    /** @return Builder<RefreshToken> */
    private function unattributedRefreshTokenQuery(): Builder
    {
        $model = Passport::refreshToken();

        return $model->newQuery()
            ->where('revoked', false)
            ->where('expires_at', '>', $this->clock->now())
            ->whereNotIn('access_token_id', Passport::token()->newQuery()->select('id')->toBase())
            ->when(
                $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), ProviderIdentityTokens::OWNER_COLUMN),
                static fn (Builder $refresh) => $refresh->whereNull(ProviderIdentityTokens::OWNER_COLUMN),
            );
    }

    /**
     * Delete a person's named Sanctum personal access tokens (the finance
     * integration's), which have no revoked flag: deleting the row is how
     * Sanctum revokes. Unless `$all`, more than one match is refused.
     *
     * @return int the number of tokens revoked
     *
     * @throws AmbiguousRevocation
     */
    public function revokePersonalAccessTokens(User $owner, string $name, bool $all, string $actor): int
    {
        return DB::transaction(function () use ($owner, $name, $all, $actor): int {
            $ids = $owner->tokens()->where('name', $name)->pluck('id')->all();
            if (! $all && count($ids) > 1) {
                throw new AmbiguousRevocation(count($ids));
            }
            // Exactly the rows counted, so a token created meanwhile is not swept in.
            $deleted = $ids === [] ? 0 : $owner->tokens()->whereKey($ids)->delete();

            DB::afterCommit(fn () => $this->log->info('personal_access_token.revoked', [
                'actor' => $actor,
                'subject_id' => $owner->public_id,
                'name' => $name,
                'revoked' => $deleted,
                'credential_fingerprints' => array_map(static fn (mixed $id): string => CredentialFingerprint::keyed((int) $id, (string) config('app.key')), $ids),
            ]));

            return $deleted;
        });
    }

    /** @return Builder<Token> */
    private function accessTokenQuery(AgentCredentialSelection $selection): Builder
    {
        $query = Passport::token()->newQuery()
            ->where('user_id', $selection->ownerId)
            ->where('revoked', false);

        return match ($selection->scope) {
            AgentCredentialScope::Token => $query->whereKey((string) $selection->tokenId),
            // Exact equality on the kind's reserved prefix, never a LIKE. An
            // expired token authenticates nothing, so it is neither a duplicate
            // of a live one nor something to report revoking.
            AgentCredentialScope::Name => $query->where('name', (string) $selection->storedName)->where('expires_at', '>', $this->clock->now()),
            // Expired rows too: one may still be the parent of a live refresh token.
            AgentCredentialScope::Connections => $selection->clientId === null ? $query : $query->where('client_id', $selection->clientId),
        };
    }

    /** @param  list<string>  $accessTokenIds */
    private function revokeRefreshTokens(AgentCredentialSelection $selection, array $accessTokenIds): int
    {
        $model = Passport::refreshToken();
        $query = $model->newQuery()->where('revoked', false);

        if ($selection->scope !== AgentCredentialScope::Connections) {
            return $accessTokenIds === [] ? 0 : $query->whereIn('access_token_id', $accessTokenIds)->update(['revoked' => true]);
        }

        // Every access token the owner ever held (for the client), revoked or
        // not - and, where the refresh table records them, the refresh tokens
        // whose access token Passport has already purged.
        $schema = $model->getConnection()->getSchemaBuilder();
        $ownerColumn = $schema->hasColumn($model->getTable(), ProviderIdentityTokens::OWNER_COLUMN);
        $clientColumn = $schema->hasColumn($model->getTable(), 'client_id');
        $parents = Passport::token()->newQuery()
            ->select('id')
            ->where('user_id', $selection->ownerId)
            ->when($selection->clientId !== null, static fn (Builder $tokens) => $tokens->where('client_id', $selection->clientId));

        $everyAccessToken = Passport::token()->newQuery()->select('id')->toBase();

        return $query->where(static function (Builder $refresh) use ($parents, $selection, $ownerColumn, $clientColumn, $everyAccessToken): void {
            $refresh->whereIn('access_token_id', $parents->toBase());
            if (! $ownerColumn) {
                return;
            }
            $refresh->orWhere(static function (Builder $orphan) use ($selection, $clientColumn, $everyAccessToken): void {
                $orphan->where(ProviderIdentityTokens::OWNER_COLUMN, (string) $selection->ownerId);
                if ($selection->clientId === null) {
                    return;
                }
                // An orphan of this owner that records no client cannot be told
                // apart by client, so a client-scoped revocation takes it too
                // rather than report a clean sweep that missed it.
                $orphan->where(static function (Builder $client) use ($selection, $clientColumn, $everyAccessToken): void {
                    if ($clientColumn) {
                        $client->where('client_id', $selection->clientId)
                            ->orWhere(static fn (Builder $unknown) => $unknown->whereNull('client_id')->whereNotIn('access_token_id', $everyAccessToken));
                    } else {
                        $client->whereNotIn('access_token_id', $everyAccessToken);
                    }
                });
            });
        })->update(['revoked' => true]);
    }

    private function revokeAuthorizationCodes(AgentCredentialSelection $selection): int
    {
        return Passport::authCode()->newQuery()
            ->where('user_id', $selection->ownerId)
            ->where('revoked', false)
            ->when($selection->clientId !== null, static fn (Builder $codes) => $codes->where('client_id', $selection->clientId))
            ->update(['revoked' => true]);
    }
}
