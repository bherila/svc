<?php

namespace App\Console\Commands;

use App\Support\AgentApi\AgentApiScopes;
use App\Support\Auth\OAuthClient;
use BWH\Auth\OAuth\Credentials\ApiCredentialService;
use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Register a first-party application - another of the operator's own
 * applications that connects to SVC on a person's behalf - as an OAuth client.
 *
 * Always a confidential, ownerless client for the authorization code grant
 * (with refresh), never client credentials: whatever it does, it does as the
 * person who connected it. Its scopes are fixed here as its ceiling. The
 * secret is printed once and only its hash is kept.
 *
 * Registering does not skip the consent screen. That takes adding the printed
 * client id to AGENT_API_FIRST_PARTY_OAUTH_CLIENTS, a separate, deliberate
 * step ({@see OAuthClient}).
 */
final class RegisterFirstPartyOAuthClientCommand extends Command
{
    protected $signature = 'svc:auth:register-first-party-client
        {name : Name shown to the person connecting it}
        {--redirect-uri=* : Exact redirect URI: https, or http on a loopback address; repeat for several}
        {--scope=* : A REST operation scope it may request; repeat for several}';

    protected $description = 'Register a first-party application as a confidential authorization-code OAuth client';

    public function handle(ClientRepository $clients, LoggerInterface $log): int
    {
        $name = trim((string) $this->argument('name'));
        /** @var list<string> $redirectUris */
        $redirectUris = array_values(array_unique(array_map('strval', (array) $this->option('redirect-uri'))));
        /** @var list<string> $scopes */
        $scopes = array_values(array_unique(array_map('strval', (array) $this->option('scope'))));

        if ($name === '' || mb_strlen($name) > 255) {
            return $this->invalid('The name must be 1 to 255 characters.');
        }
        if ($redirectUris === []) {
            return $this->invalid('Give at least one --redirect-uri.');
        }
        foreach ($redirectUris as $uri) {
            if (! ApiCredentialService::validRedirectUri($uri)) {
                return $this->invalid('Redirect URIs must be https, or http on a loopback address, with no fragment or credentials.');
            }
        }
        if ($scopes === []) {
            return $this->invalid('Give at least one --scope.');
        }
        foreach ($scopes as $scope) {
            if (! in_array($scope, AgentApiScopes::rest(), true)) {
                return $this->invalid("[{$scope}] is not a REST operation scope.");
            }
        }

        $model = Passport::client();
        $connection = $model->getConnection();
        $registered = $connection->transaction(function () use ($clients, $model, $name, $redirectUris, $scopes): ?array {
            // One live client per name, so the allow-list names an
            // unambiguous application; rotate by revoking the old one first.
            if ($model->newQuery()->where('name', $name)->where('revoked', false)->exists()) {
                return null;
            }
            $client = $clients->createAuthorizationCodeGrantClient($name, $redirectUris, confidential: true);
            $client->forceFill(['scopes' => $client->hasCast('scopes', ['array', 'json', 'collection'])
                ? $scopes
                : json_encode($scopes, JSON_THROW_ON_ERROR)])->save();

            return ['client_id' => (string) $client->getKey(), 'client_secret' => (string) $client->plainSecret];
        });
        if ($registered === null) {
            $this->error('A live OAuth client with that name already exists.');

            return self::FAILURE;
        }

        $log->info('oauth_client.registered', ['actor' => 'console', 'client_id' => $registered['client_id'], 'first_party' => true]);
        // Raw: the console formatter would otherwise read markup in the name.
        $this->output->writeln((string) json_encode([
            'client_id' => $registered['client_id'],
            'client_secret' => $registered['client_secret'],
            'name' => $name,
            'redirect_uris' => $redirectUris,
            'scopes' => $scopes,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
        $this->line('The secret is shown only now. To skip the consent screen for this client, add its client_id to AGENT_API_FIRST_PARTY_OAUTH_CLIENTS.');

        return self::SUCCESS;
    }

    private function invalid(string $message): int
    {
        $this->error($message);

        return self::INVALID;
    }
}
