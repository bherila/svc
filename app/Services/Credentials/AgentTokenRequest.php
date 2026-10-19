<?php

namespace App\Services\Credentials;

use App\Support\AgentApi\AgentApiScopes;

/**
 * One agent token to issue, already checked: a label that fits the stored
 * column under its kind's prefix, exactly the registry's scopes deduplicated
 * in the order given, the one resource those scopes leave, and a lifetime of
 * one to {@see MAX_TTL_MINUTES} minutes.
 *
 * A token is bound to one resource. Naming `mcp:use` makes it an MCP token
 * (APP_URL/api/v1/mcp); otherwise it is a REST token (APP_URL/api/v1). The
 * REST resource never admits `mcp:use`, and an MCP connection cannot open
 * without it, so the scopes leave only one choice.
 *
 * Database-free, so every refusal is decided before anything is looked up.
 */
final readonly class AgentTokenRequest
{
    public const int MAX_TTL_MINUTES = 240;

    /** The stored name column's width. */
    private const int NAME_COLUMN = 255;

    /**
     * @param  non-empty-list<string>  $scopes
     * @param  'mcp'|'rest'  $resource
     */
    private function __construct(
        public AgentTokenKind $kind,
        public string $label,
        public array $scopes,
        public string $resource,
        public int $ttlMinutes,
    ) {}

    /**
     * @param  array<array-key, mixed>  $scopes
     *
     * @throws InvalidAgentTokenRequest naming the first thing wrong
     */
    public static function of(AgentTokenKind $kind, string $label, array $scopes, int $ttlMinutes): self
    {
        $label = trim($label);
        $maxLabel = self::NAME_COLUMN - strlen($kind->namePrefix());
        if ($label === '' || mb_strlen($label) > $maxLabel || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            throw new InvalidAgentTokenRequest("The token name must be 1-{$maxLabel} characters with no control characters.");
        }

        $scopes = self::knownScopes($scopes);
        if ($scopes === null) {
            throw new InvalidAgentTokenRequest('Name at least one scope, each one of: '.implode(', ', array_keys(AgentApiScopes::descriptions())).'.');
        }
        $resource = in_array(AgentApiScopes::MCP_USE, $scopes, true) ? 'mcp' : 'rest';
        /**
         * @infection-ignore-all Unreachable with today's registry, where each ceiling is every
         * scope the choice of resource leaves; kept so a scope one resource stops admitting is
         * refused here rather than by the repository after a token row exists.
         */
        if (($beyond = array_values(array_diff($scopes, $resource === 'mcp' ? AgentApiScopes::mcp() : AgentApiScopes::rest()))) !== []) {
            throw new InvalidAgentTokenRequest("A {$resource} token cannot carry: ".implode(', ', $beyond).'.');
        }

        if ($ttlMinutes < 1 || $ttlMinutes > self::MAX_TTL_MINUTES) {
            throw new InvalidAgentTokenRequest('The lifetime must be a whole number of minutes from 1 to '.self::MAX_TTL_MINUTES.'.');
        }

        return new self($kind, $label, $scopes, $resource, $ttlMinutes);
    }

    /**
     * A lifetime typed at a shell: digits only, within the bounds; null otherwise.
     */
    public static function ttlFromInput(string $value): ?int
    {
        $value = trim($value);
        if (preg_match('/^\d{1,4}$/', $value) !== 1) {
            return null;
        }
        /** @infection-ignore-all Equivalent: the value is all digits, so the comparisons and the ?int return coerce it identically without the cast. */
        $minutes = (int) $value;

        return $minutes >= 1 && $minutes <= self::MAX_TTL_MINUTES ? $minutes : null;
    }

    /** The name the token row carries: the kind's prefix, then the label. */
    public function storedName(): string
    {
        return $this->kind->namePrefix().$this->label;
    }

    /**
     * @param  array<array-key, mixed>  $requested
     * @return non-empty-list<string>|null
     */
    private static function knownScopes(array $requested): ?array
    {
        $known = array_keys(AgentApiScopes::descriptions());
        $scopes = [];
        foreach ($requested as $scope) {
            $scope = is_string($scope) ? trim($scope) : null;
            if (! in_array($scope, $known, true)) {
                return null;
            }
            if (! in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }

        return $scopes === [] ? null : $scopes;
    }
}
