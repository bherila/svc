<?php

namespace App\Services\Mcp\Toolsets;

/**
 * The toolsets a principal's tools fall into, derived from the scopes the
 * tools declare, so that the operation catalog stays the one source of truth.
 *
 * A tool's toolset is the group of its defining scope: the part before the
 * colon of the first scope that is not a plain read, or of its first scope
 * when every scope is a read. A tool that also reads another group (a task
 * write that reads clients) therefore stays with the group it changes. A new
 * scope group becomes a toolset with no further code.
 *
 * Integrations are the one exception. An operation whose name has a segment
 * belonging to a named integration (`square.sync`, `invoices.square_link`)
 * belongs to that integration's toolset, because it is gated by a deployment
 * switch and not by a scope group of its own.
 *
 * This class holds no state of a request and touches neither the database nor
 * the configuration: callers pass in only the tools the principal is already
 * authorized for, so every count here is a count of tools discovery lists.
 */
final readonly class McpToolsets
{
    /** The toolset of a tool that declares no scope at all. */
    public const string GENERAL = 'general';

    /**
     * Integrations that form their own toolset whatever scope their tools
     * declare. The segment must equal the name or continue it after an
     * underscore.
     *
     * @var list<string>
     */
    private const array INTEGRATIONS = ['square'];

    /**
     * Copy for a toolset whose scope descriptions would describe it wrongly.
     *
     * @var array<string, string>
     */
    private const array DESCRIPTIONS = [
        'square' => 'Read Square directly, work with the local Square mirror, and link, cover or reconcile Square invoices and payments against SVC invoices.',
    ];

    /**
     * @param  array<string, list<string>>  $toolsByToolset  tool names, by toolset name, both sorted
     * @param  array<string, string>  $toolsetByTool
     * @param  array<string, string>  $descriptions
     */
    private function __construct(
        private array $toolsByToolset,
        private array $toolsetByTool,
        private array $descriptions,
    ) {}

    /**
     * @param  array<string, list<string>>  $scopesByTool  the scopes each authorized tool requires, by tool name
     * @param  array<string, string>  $scopeDescriptions  scope identifier to its description
     */
    public static function derive(array $scopesByTool, array $scopeDescriptions): self
    {
        $toolsByToolset = [];
        $toolsetByTool = [];
        foreach ($scopesByTool as $tool => $scopes) {
            $toolset = self::toolsetFor((string) $tool, $scopes);
            $toolsByToolset[$toolset][] = (string) $tool;
            $toolsetByTool[(string) $tool] = $toolset;
        }
        ksort($toolsByToolset);
        $descriptions = [];
        foreach ($toolsByToolset as $toolset => $tools) {
            sort($tools);
            $toolsByToolset[$toolset] = $tools;
            $descriptions[$toolset] = self::describe((string) $toolset, $scopeDescriptions);
        }

        return new self($toolsByToolset, $toolsetByTool, $descriptions);
    }

    /** @param  list<string>  $scopes */
    public static function toolsetFor(string $tool, array $scopes): string
    {
        foreach (explode('.', $tool) as $segment) {
            foreach (self::INTEGRATIONS as $integration) {
                if ($segment === $integration || str_starts_with($segment, $integration.'_')) {
                    return $integration;
                }
            }
        }
        if ($scopes === []) {
            return self::GENERAL;
        }
        $defining = $scopes[0];
        foreach ($scopes as $scope) {
            if (! str_ends_with($scope, ':read')) {
                $defining = $scope;
                break;
            }
        }

        return explode(':', $defining)[0];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map('strval', array_keys($this->toolsByToolset));
    }

    public function has(string $toolset): bool
    {
        return isset($this->toolsByToolset[$toolset]);
    }

    /** @return list<string> */
    public function tools(string $toolset): array
    {
        return $this->toolsByToolset[$toolset] ?? [];
    }

    public function toolsetOf(string $tool): ?string
    {
        return $this->toolsetByTool[$tool] ?? null;
    }

    public function description(string $toolset): string
    {
        return $this->descriptions[$toolset] ?? '';
    }

    /**
     * What toolsets.list reports.
     *
     * @return list<array{name: string, description: string, tool_count: int, enabled: bool}>
     */
    public function report(McpToolsetSelection $selection): array
    {
        $report = [];
        foreach ($this->toolsByToolset as $toolset => $tools) {
            $report[] = [
                'name' => (string) $toolset,
                'description' => $this->descriptions[$toolset],
                'tool_count' => count($tools),
                'enabled' => $selection->includes((string) $toolset),
            ];
        }

        return $report;
    }

    /** @param  array<string, string>  $scopeDescriptions */
    private static function describe(string $toolset, array $scopeDescriptions): string
    {
        if (isset(self::DESCRIPTIONS[$toolset])) {
            return self::DESCRIPTIONS[$toolset];
        }
        if (isset($scopeDescriptions[$toolset.':read'])) {
            return $scopeDescriptions[$toolset.':read'];
        }
        foreach ($scopeDescriptions as $scope => $description) {
            if (str_starts_with((string) $scope, $toolset.':')) {
                return $description;
            }
        }

        return "Tools gated by the {$toolset}:* scopes.";
    }
}
