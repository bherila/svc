<?php

namespace App\Providers;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\Workspace;
use App\Policies\ClientCompanyPolicy;
use App\Policies\ClientProjectPolicy;
use App\Policies\WorkspacePolicy;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\ResourceAccessTokenRepository;
use App\Support\AgentApi\ResourceAuthCodeRepository;
use App\Support\AgentApi\ResourceRefreshTokenRepository;
use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;
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
        $this->app->bind(InternalAgentApiTransport::class, fn ($app): InternalAgentApiTransport => new InternalAgentApiTransport(
            router: $app->make(Router::class),
            exceptions: $app->make(ExceptionHandler::class),
            outerRequest: $app->make('request'),
            application: $app,
            allowedHeaders: ['Idempotency-Key'],
            temporaryFilePrefix: 'svc-agent-',
        ));
        $this->app->bind(AccessTokenRepository::class, ResourceAccessTokenRepository::class);
        $this->app->bind(AuthCodeRepository::class, ResourceAuthCodeRepository::class);
        $this->app->bind(RefreshTokenRepository::class, ResourceRefreshTokenRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::loadKeysFrom(storage_path('app/private/oauth'));
        Passport::authorizationView('oauth.authorize');
        Passport::tokensCan(AgentApiScopes::descriptions());
        Passport::tokensExpireIn(now()->addMinutes(15));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        HandleCors::skipWhen(fn (Request $request): bool => $request->is('api/v1/mcp'));
        Gate::policy(Workspace::class, WorkspacePolicy::class);
        Gate::policy(ClientCompany::class, ClientCompanyPolicy::class);
        Gate::policy(ClientProject::class, ClientProjectPolicy::class);

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
