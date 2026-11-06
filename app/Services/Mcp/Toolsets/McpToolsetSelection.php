<?php

namespace App\Services\Mcp\Toolsets;

/**
 * Which toolsets one MCP session has enabled.
 *
 * The session is cached per credential, so this is state of one connection and
 * never of a person: two credentials of the same person keep separate
 * selections. It only widens or narrows which of the already authorized tools
 * discovery lists; it authorizes nothing.
 */
final readonly class McpToolsetSelection
{
    /** The key under which the selection lives in the MCP session. */
    public const string SESSION_KEY = 'svc.mcp.toolsets';

    /** The request header that opts a connection into the full tool list. */
    public const string CONNECT_HEADER = 'X-SVC-Toolsets';

    /** @param  list<string>  $enabled */
    private function __construct(
        public bool $all,
        public array $enabled,
    ) {}

    /** Progressive discovery with nothing enabled: the default for every client. */
    public static function progressive(): self
    {
        return new self(false, []);
    }

    /** Every authorized tool is listed from the start, as before progressive discovery. */
    public static function everything(): self
    {
        return new self(true, []);
    }

    /** Only the value `all` opts out; anything else keeps the default. */
    public static function fromConnectHeader(?string $value): self
    {
        return $value !== null && strtolower(trim($value)) === 'all' ? self::everything() : self::progressive();
    }

    /**
     * Read back what {@see self::toSession()} stored.
     *
     * A session with nothing stored was opened before progressive discovery
     * existed and has been listing every tool all along: it keeps doing so
     * until it expires, rather than losing tools mid-session with no
     * notification. A stored value that cannot be read falls back to the
     * default, which lists less.
     */
    public static function fromSession(mixed $stored): self
    {
        if ($stored === null) {
            return self::everything();
        }
        $stored = is_array($stored) ? $stored : [];
        if (($stored['all'] ?? false) === true) {
            return self::everything();
        }
        $enabled = [];
        foreach (is_array($stored['enabled'] ?? null) ? $stored['enabled'] : [] as $name) {
            if (is_string($name) && $name !== '' && ! in_array($name, $enabled, true)) {
                $enabled[] = $name;
            }
        }

        return new self(false, $enabled);
    }

    /** @return array{all: bool, enabled: list<string>} */
    public function toSession(): array
    {
        return ['all' => $this->all, 'enabled' => $this->enabled];
    }

    public function includes(string $toolset): bool
    {
        return $this->all || in_array($toolset, $this->enabled, true);
    }

    /** @param  list<string>  $toolsets */
    public function enable(array $toolsets): self
    {
        if ($this->all) {
            return $this;
        }
        $enabled = $this->enabled;
        foreach ($toolsets as $toolset) {
            if (! in_array($toolset, $enabled, true)) {
                $enabled[] = $toolset;
            }
        }

        return new self(false, $enabled);
    }

    /** @param  list<string>  $toolsets */
    public function disable(array $toolsets): self
    {
        if ($this->all) {
            return $this;
        }

        return new self(false, array_values(array_filter(
            $this->enabled,
            static fn (string $enabled): bool => ! in_array($enabled, $toolsets, true),
        )));
    }
}
