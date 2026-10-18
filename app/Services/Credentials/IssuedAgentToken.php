<?php

namespace App\Services\Credentials;

use Carbon\CarbonImmutable;
use Closure;
use JsonSerializable;
use LogicException;

/**
 * A freshly issued agent token. The bearer value exists only here, for the
 * caller to hand over once through {@see token()}; it is never logged.
 *
 * The value is held inside a closure rather than a property, so nothing that
 * walks an object's properties can reach it: `var_export` prints a closure
 * without what it captured, and `json_encode`, Monolog's normalizer,
 * `serialize`, `print_r` and `var_dump` all get the redacted shape below.
 */
final readonly class IssuedAgentToken implements JsonSerializable
{
    /** @var Closure(): string */
    private Closure $bearer;

    /** @param  non-empty-list<string>  $scopes */
    public function __construct(
        public string $id,
        #[\SensitiveParameter] string $token,
        public CarbonImmutable $expiresAt,
        public string $label,
        public array $scopes,
        public string $resource,
    ) {
        $this->bearer = static fn (): string => $token;
    }

    /** The bearer value, to show the operator once. */
    public function token(): string
    {
        return ($this->bearer)();
    }

    /** What an audit entry names the credential by: never its id or value. */
    public function fingerprint(): string
    {
        return CredentialFingerprint::of($this->id);
    }

    /** @return array{fingerprint: string, token: string, expiresAt: string, label: string, scopes: non-empty-list<string>, resource: string} */
    private function redacted(): array
    {
        return [
            'fingerprint' => $this->fingerprint(),
            'token' => '[redacted]',
            'expiresAt' => $this->expiresAt->toIso8601String(),
            'label' => $this->label,
            'scopes' => $this->scopes,
            'resource' => $this->resource,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->redacted();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->redacted();
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return $this->redacted();
    }

    /**
     * A serialized token was redacted, so it cannot come back as one.
     *
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('An issued agent token cannot be unserialized: its bearer value was never serialized.');
    }
}
