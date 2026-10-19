<?php

namespace App\Providers;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\Workspace;
use App\Policies\ClientCompanyPolicy;
use App\Policies\ClientProjectPolicy;
use App\Policies\WorkspacePolicy;
use App\Services\Access\DelegatedStaffAccess;
use App\Services\AgentApi\Operations\AgentAvailability;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\AgentPrincipalResolver;
use App\Services\ApiCredentials\OpenApiGrantableScopes;
use App\Services\Billing\ReplayHistoryBasis;
use App\Services\Credentials\RevokeCredentialsIssuedDuringRevocation;
use App\Services\Mcp\Context\McpPrincipalResolver;
use App\Services\Mcp\Context\McpPrincipalResolverInterface;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\InternalApiDispatch;
use App\Support\AgentApi\MarkedInternalAgentApiTransport;
use App\Support\AgentApi\ProviderIdentityWrites;
use App\Support\Auth\OAuthClient;
use App\Support\Auth\PersonalAccessTokenIdentity;
use Bherila\McpLaravelBridge\Capabilities\Availability;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\PrincipalResolver;
use Bherila\McpLaravelBridge\Http\AgentApiTransport;
use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Bherila\McpLaravelBridge\Http\McpHttpPolicy;
use Bherila\McpLaravelBridge\Http\OperationRoutes;
use BWH\Auth\OAuth\Credentials\GrantableScopes;
use BWH\Auth\OAuth\DelegatedAccess\ApplicationAccessAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Events\RefreshTokenCreated;
use Laravel\Passport\Passport;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Passport::$deviceCodeGrantEnabled = false;
        // Before the auth package boots, which installs its resource client
        // only while Passport still has its own: SVC's subclass keeps that
        // enforcement and adds the allow-listed first-party consent exception.
        Passport::useClientModel(OAuthClient::class);
        $this->app->singleton(ReplayHistoryBasis::class);
        // Holds which credential an MCP write tool call already verified, for that call only.
        $this->app->singleton(ProviderIdentityWrites::class);
        // Offer a REST credential only scopes some REST operation requires.
        $this->app->bind(GrantableScopes::class, OpenApiGrantableScopes::class);
        $this->app->bind(McpPrincipalResolverInterface::class, McpPrincipalResolver::class);
        // Workspace staff, managed through the identity provider. The package's endpoint exists
        // only while this is bound, and answers 404 until delegated access is enabled.
        $this->app->bind(ApplicationAccessAdapter::class, DelegatedStaffAccess::class);
        // One declaration per agent operation (#408). The registry is read-only
        // after it is built; deployment switches are re-read on every
        // evaluation, so a long-lived worker sees configuration changes.
        $this->app->singleton(AgentOperationCatalog::class);
        $this->app->singleton(OperationRegistry::class, static fn ($app): OperationRegistry => $app->make(AgentOperationCatalog::class)->registry());
        // The REST route gate's view: cutovers and scopes.
        $this->app->bind(Availability::class, static fn ($app): Availability => $app->make(AgentAvailability::class)->rest());
        $this->app->bind(PrincipalResolver::class, AgentPrincipalResolver::class);
        // The nested request is marked, so the REST throttle counts the
        // client's MCP request once rather than twice (#459).
        $this->app->scoped(InternalApiDispatch::class);
        $this->app->bind(InternalAgentApiTransport::class, fn ($app): InternalAgentApiTransport => new MarkedInternalAgentApiTransport(
            router: $app->make(Router::class),
            exceptions: $app->make(ExceptionHandler::class),
            outerRequest: $app->make('request'),
            application: $app,
            // The REST route runs on the caller's own scheme and host, so the
            // links it returns (web_url, signed downloads) point where the
            // caller reached SVC rather than at a bare `localhost`.
            apiPrefix: $app->make('request')->getSchemeAndHttpHost().'/api/v1',
            allowedHeaders: ['Idempotency-Key', 'X-Request-Id'],
            temporaryFilePrefix: 'svc-agent-',
        ));
        // MCP tools reach their REST operation through this (#459).
        $this->app->bind(AgentApiTransport::class, InternalAgentApiTransport::class);
        $this->app->singleton(McpHttpPolicy::class, static fn (): McpHttpPolicy => new McpHttpPolicy(
            allowedOrigins: static function (): array {
                $origins = config('agent_api.mcp_allowed_origins', []);

                return is_array($origins)
                    ? array_values(array_filter($origins, static fn (mixed $origin): bool => is_string($origin) && $origin !== ''))
                    : [];
            },
            allowedHosts: static function (): array {
                $configured = config('agent_api.mcp_allowed_hosts', []);
                if (is_array($configured) && $configured !== []) {
                    return array_values(array_filter($configured, static fn (mixed $host): bool => is_string($host) && $host !== ''));
                }

                $hosts = [];
                foreach ([config('app.url'), config('bherila-auth.oauth_server.resource')] as $url) {
                    if (is_string($url)) {
                        $host = McpHttpPolicy::hostFromUrl($url);
                        if (! in_array($host, $hosts, true)) {
                            $hosts[] = $host;
                        }
                    }
                }

                return $hosts;
            },
            maxRequestBodyBytes: Config::integer('agent_api.mcp_max_body_bytes'),
            maxResponseBodyBytes: Config::integer('agent_api.mcp_max_response_body_bytes'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        OperationRoutes::macro();
        Passport::loadKeysFrom(storage_path('app/private/oauth'));
        Passport::authorizationView('bherila-auth::oauth.authorize');
        Passport::tokensCan(AgentApiScopes::descriptions());
        Passport::tokensExpireIn(now()->addMinutes(15));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        // Synchronous, as Passport persists each credential: whatever a grant
        // in flight during a revocation mints is revoked before it is returned.
        Event::listen(AccessTokenCreated::class, [RevokeCredentialsIssuedDuringRevocation::class, 'accessTokenCreated']);
        Event::listen(RefreshTokenCreated::class, [RevokeCredentialsIssuedDuringRevocation::class, 'refreshTokenCreated']);
        // Passport announces no authorization code, so the model's own created event.
        Event::listen('eloquent.created: '.Passport::$authCodeModel, [RevokeCredentialsIssuedDuringRevocation::class, 'authCodeCreated']);
        HandleCors::skipWhen(fn (Request $request): bool => $request->is('api/v1/mcp'));
        // Sanctum personal access tokens follow their owner's provider identity
        // (a no-op until provider identity enforcement is enabled).
        Sanctum::authenticateAccessTokensUsing(
            static fn (PersonalAccessToken $token, bool $isValid): bool => app(PersonalAccessTokenIdentity::class)->accepts($token, $isValid),
        );
        Gate::policy(Workspace::class, WorkspacePolicy::class);
        Gate::policy(ClientCompany::class, ClientCompanyPolicy::class);
        Gate::policy(ClientProject::class, ClientProjectPolicy::class);
        RateLimiter::for('brevo-webhooks', fn (Request $request): Limit => Limit::perMinute(
            (int) config('services.brevo.webhook_rate_limit_per_minute', 600),
        )->by($request->ip() ?? 'unknown'));

        $this->configureDefaults();

        $this->app->make(MailManager::class)->extend('brevo', function (array $config) {
            $configuration = $this->app->make('config');

            return (new BrevoTransportFactory)->create(
                Dsn::fromString($configuration->get('services.brevo.dsn')),
            );
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
