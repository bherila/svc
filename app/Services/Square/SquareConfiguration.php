<?php

namespace App\Services\Square;

use App\Models\Workspace;
use App\Services\Tenancy\WorkspacePublicIdResolver;
use LogicException;
use SensitiveParameter;

/**
 * The one Square connection, read from server-side configuration on every
 * use (#438). It is either complete - a token, a known environment, a pinned
 * version and a bound workspace - or the feature is off.
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
