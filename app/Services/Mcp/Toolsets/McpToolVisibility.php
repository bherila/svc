<?php

namespace App\Services\Mcp\Toolsets;

/**
 * Which of the tools a principal is authorized for tools/list shows.
 *
 * The core set is always listed. Every other tool is listed once the session
 * has enabled its toolset, or from the start when the connection opted out of
 * progressive discovery. Visibility is narrower than authorization and never
 * wider: callers pass in only tools that discovery already allows.
 */
final readonly class McpToolVisibility
{
    /** The tools that list, enable and disable toolsets: always listed, never in a toolset. */
    public const array TOOLSET_TOOLS = ['toolsets.list', 'toolsets.enable', 'toolsets.disable'];

    /** Tools every connection lists to orient itself, whatever it enables. */
    public const array ORIENTATION_TOOLS = ['context.get', 'search'];

    /** The prompt whose tools join the core set, so time can be logged from the start. */
    public const string TIME_LOGGING_PROMPT = 'log-time-across-projects';

    /**
     * The core set: orientation, the toolset tools, and the tools the
     * time-logging prompt walks through.
     *
     * @param  list<string>  $timeLoggingPromptTools
     * @return list<string>
     */
    public static function core(array $timeLoggingPromptTools): array
    {
        return array_values(array_unique([...self::ORIENTATION_TOOLS, ...self::TOOLSET_TOOLS, ...$timeLoggingPromptTools]));
    }

    /** @param  list<string>  $core  tool names listed whatever is enabled */
    public function __construct(
        private McpToolsets $toolsets,
        private array $core,
    ) {}

    public function isVisible(string $tool, McpToolsetSelection $selection): bool
    {
        if ($selection->all || in_array($tool, $this->core, true)) {
            return true;
        }
        $toolset = $this->toolsets->toolsetOf($tool);

        return $toolset !== null && $selection->includes($toolset);
    }

    /**
     * The toolsets that must be enabled for every named tool to be listed.
     *
     * @param  list<string>  $tools
     * @return list<string>
     */
    public function toolsetsNeededFor(array $tools, McpToolsetSelection $selection): array
    {
        $needed = [];
        foreach ($tools as $tool) {
            if ($this->isVisible($tool, $selection)) {
                continue;
            }
            $toolset = $this->toolsets->toolsetOf($tool);
            if ($toolset !== null && ! in_array($toolset, $needed, true)) {
                $needed[] = $toolset;
            }
        }

        return $needed;
    }
}
