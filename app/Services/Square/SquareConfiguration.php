<?php

namespace App\Services\Square;

use App\Models\Workspace;
use App\Services\Payments\PaymentConnectionResolver;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Services\Tenancy\WorkspacePublicIdResolver;
use App\Support\Payments\PaymentProcessor;
use LogicException;
use SensitiveParameter;

/**
 * A workspace's Square connection, read on every use (#438). It is either
 * complete - a token, a known environment, a pinned version and the workspace
 * it serves - or the feature is off for that workspace.
 *
 * {@see for()} resolves it from the workspace's own payment connection. The
 * environment's single account serves only until the migration command has
 * run ({@see legacy()}).
 *
 * The token is held privately and never printed: var_dump/print_r show it
 * redacted and the object refuses to serialize, so it cannot reach a log, a
 * queued payload or a cache entry by accident.
 */
final readonly class SquareConfiguration
{
    public const string PRODUCTION = 'production';

    public const string SANDBOX = 'sandbox';

    private const array BASE_URLS = [
        self::PRODUCTION => 'https://connect.squareup.com',
        self::SANDBOX => 'https://connect.squareupsandbox.com',
    ];

    public function __construct(
        #[SensitiveParameter] private ?string $accessToken,
        public string $environment,
        public ?string $applicationId,
        public string $version,
        public ?string $workspacePublicId,
        public float $timeoutSeconds = 10.0,
        public float $connectTimeoutSeconds = 5.0,
        public int $maxAttempts = 3,
        public int $retryBaseMilliseconds = 250,
        public int $rateLimitPerMinute = 60,
    ) {}

    /** This workspace's Square configuration, from its own connection and no other. */
    public static function for(Workspace $workspace): self
    {
        $environmentValues = self::fromConfig();
        $connections = app(WorkspacePaymentConnections::class);
        $row = $connections->active($workspace, PaymentProcessor::Square);
        if ($row !== null) {
            $source = $connections->credentialSource($row);
            $environment = $source?->environment;

            return $environmentValues->bound(
                $source?->secret(),
                is_string($environment) ? $environment : self::PRODUCTION,
                (string) $workspace->public_id,
            );
        }
        if (app(PaymentConnectionResolver::class)->environmentMayServe($workspace, PaymentProcessor::Square)
            && $environmentValues->workspacePublicId !== null
            && hash_equals($environmentValues->workspacePublicId, (string) $workspace->public_id)) {
            return $environmentValues;
        }

        return $environmentValues->bound(null, $environmentValues->environment, null);
    }

    /** The environment's single account, while the migration command has not run; otherwise nothing. */
    public static function legacy(): self
    {
        $environmentValues = self::fromConfig();

        return app(WorkspacePaymentConnections::class)->environmentMigrated(PaymentProcessor::Square)
            ? $environmentValues->bound(null, $environmentValues->environment, null)
            : $environmentValues;
    }

    /** Whether any workspace on this deployment can use Square. */
    public static function deploymentEnabled(): bool
    {
        return self::legacy()->isConfigured()
            || app(WorkspacePaymentConnections::class)->allActive(PaymentProcessor::Square)->isNotEmpty();
    }

    /**
     * Every workspace Square serves: those with their own connection, and the
     * environment's bound workspace until the migration command has run.
     *
     * @return list<Workspace>
     */
    public static function servedWorkspaces(): array
    {
        $workspaces = [];
        foreach (app(WorkspacePaymentConnections::class)->allActive(PaymentProcessor::Square) as $row) {
            $workspace = Workspace::query()->whereKey($row->workspace_id)->first();
            if ($workspace !== null) {
                $workspaces[$workspace->id] = $workspace;
            }
        }
        $legacy = self::legacy()->workspace();
        if ($legacy !== null && ! isset($workspaces[$legacy->id]) && self::for($legacy)->isConfigured()) {
            $workspaces[$legacy->id] = $legacy;
        }
        ksort($workspaces);

        return array_values($workspaces);
    }

    private function bound(#[SensitiveParameter] ?string $token, string $environment, ?string $workspacePublicId): self
    {
        return new self(
            $token, strtolower($environment), $this->applicationId, $this->version, $workspacePublicId,
            $this->timeoutSeconds, $this->connectTimeoutSeconds, $this->maxAttempts, $this->retryBaseMilliseconds, $this->rateLimitPerMinute,
        );
    }

    /** The raw environment values, which {@see for()} and {@see legacy()} qualify. */
    public static function fromConfig(): self
    {
        $value = static fn (string $key): ?string => is_string($raw = config('services.square.'.$key)) && trim($raw) !== '' ? trim($raw) : null;

        return new self(
            $value('access_token'),
            strtolower($value('environment') ?? self::PRODUCTION),
            $value('application_id'),
            $value('version') ?? '',
            $value('workspace'),
            self::bounded((float) config('services.square.timeout_seconds', 10), 1.0, 30.0),
            self::bounded((float) config('services.square.connect_timeout_seconds', 5), 1.0, 10.0),
            (int) self::bounded((float) config('services.square.max_attempts', 3), 1, 5),
            (int) self::bounded((float) config('services.square.retry_base_milliseconds', 250), 0, 2000),
            (int) self::bounded((float) config('services.square.rate_limit_per_minute', 60), 1, 600),
        );
    }

    /** Complete enough to call Square: anything less leaves the feature off. */
    public function isConfigured(): bool
    {
        return $this->accessToken !== null
            && $this->workspacePublicId !== null
            && isset(self::BASE_URLS[$this->environment])
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $this->version) === 1;
    }

    /** Square is connected to this workspace, and only this one. */
    public function servesWorkspace(Workspace $workspace): bool
    {
        return $this->isConfigured()
            && hash_equals((string) $this->workspacePublicId, (string) $workspace->public_id);
    }

    public function workspace(): ?Workspace
    {
        return $this->isConfigured()
            ? app(WorkspacePublicIdResolver::class)->find((string) $this->workspacePublicId)
            : null;
    }

    public function baseUrl(): string
    {
        return self::BASE_URLS[$this->environment] ?? throw new LogicException('Square is not configured.');
    }

    /** For the client's Authorization header only. */
    public function accessToken(): string
    {
        return $this->accessToken ?? throw new LogicException('Square is not configured.');
    }

    /** Whether the credential occurs in a text svc is about to hand out. */
    public function credentialAppearsIn(string $text): bool
    {
        return $this->accessToken !== null && str_contains($text, $this->accessToken);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => $this->accessToken === null ? null : '[redacted]',
            'environment' => $this->environment,
            'version' => $this->version,
            'workspacePublicId' => $this->workspacePublicId,
        ];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('The Square configuration holds a credential and is never serialized.');
    }

    private static function bounded(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
