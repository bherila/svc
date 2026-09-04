<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\Passport;

final class PruneDynamicOAuthClientsCommand extends Command
{
    protected $signature = 'svc:oauth:prune-dynamic-clients {--days= : Override the configured retention window} {--pretend : Report without deleting}';

    protected $description = 'Delete stale, unused dynamically registered public OAuth clients';

    public function handle(): int
    {
        $clientModel = Passport::client();
        if (! $clientModel->getConnection()->getSchemaBuilder()->hasColumns(
            $clientModel->getTable(),
            ['dynamically_registered_at', 'last_used_at'],
        )) {
            $this->warn('Dynamic OAuth client metadata is not migrated yet; nothing was pruned.');

            return self::SUCCESS;
        }
        $days = $this->option('days');
        $days = $days === null ? (int) config('agent_api.dynamic_client_retention_days', 30) : filter_var($days, FILTER_VALIDATE_INT);
        if (! is_int($days) || $days < 1 || $days > 3650) {
            $this->error('Retention days must be an integer from 1 through 3650.');

            return self::INVALID;
        }
        if ($this->hasUnattributedActiveRefreshCredential()) {
            $this->warn('An active refresh credential has no access-token row; dynamic OAuth client pruning was deferred.');

            return self::SUCCESS;
        }
        $cutoff = now()->subDays($days);
        $candidates = Passport::client()->newQuery()
            ->whereNotNull('dynamically_registered_at')
            ->where('dynamically_registered_at', '<', $cutoff)
            ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<', $cutoff))
            ->orderBy('id')
            ->get();
        $pruned = 0;
        foreach ($candidates as $client) {
            if ($this->hasActiveCredential((string) $client->getKey())) {
                continue;
            }
            $pruned++;
            if ($this->option('pretend')) {
                continue;
            }
            $client->getConnection()->transaction(function () use ($client): void {
                $tokenIds = Passport::token()->newQuery()->where('client_id', $client->getKey())->pluck('id');
                if ($tokenIds->isNotEmpty()) {
                    Passport::refreshToken()->newQuery()->whereIn('access_token_id', $tokenIds)->delete();
                    Passport::token()->newQuery()->whereIn('id', $tokenIds)->delete();
                }
                Passport::authCode()->newQuery()->where('client_id', $client->getKey())->delete();
                $client->delete();
            });
        }

        $this->info(($this->option('pretend') ? 'Would prune ' : 'Pruned ').$pruned.' stale dynamic OAuth client(s).');

        return self::SUCCESS;
    }

    private function hasActiveCredential(string $clientId): bool
    {
        $now = now();
        if (Passport::token()->newQuery()->where('client_id', $clientId)->where('revoked', false)->where('expires_at', '>', $now)->exists()) {
            return true;
        }

        return Passport::refreshToken()->newQuery()
            ->where('revoked', false)
            ->where('expires_at', '>', $now)
            ->whereIn('access_token_id', Passport::token()->newQuery()->select('id')->where('client_id', $clientId))
            ->exists();
    }

    private function hasUnattributedActiveRefreshCredential(): bool
    {
        return Passport::refreshToken()->newQuery()
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->whereNotIn('access_token_id', Passport::token()->newQuery()->select('id'))
            ->exists();
    }
}
