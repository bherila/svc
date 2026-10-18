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
    ) {}

    /**
     * @throws AmbiguousRevocation when more access tokens match than the selection allows
     */
    public function revoke(AgentCredentialSelection $selection, string $actor): AgentRevocation
    {
        $connection = Passport::token()->getConnection();

        return $connection->transaction(function () use ($selection, $actor, $connection): AgentRevocation {
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
                'credential_fingerprints' => CredentialFingerprint::all($ids),
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
            AgentCredentialScope::Name => $query->where('name', (string) $selection->storedName())->where('expires_at', '>', $this->clock->now()),
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

        return $query->where(static function (Builder $refresh) use ($parents, $selection, $ownerColumn, $clientColumn): void {
            $refresh->whereIn('access_token_id', $parents->toBase());
            // Without its own client record an orphan cannot be told apart by
            // client, so a client-scoped revocation leaves it to its parent.
            if ($ownerColumn && ($selection->clientId === null || $clientColumn)) {
                $refresh->orWhere(static function (Builder $orphan) use ($selection): void {
                    $orphan->where(ProviderIdentityTokens::OWNER_COLUMN, (string) $selection->ownerId)
                        ->when($selection->clientId !== null, static fn (Builder $scoped) => $scoped->where('client_id', $selection->clientId));
                });
            }
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
