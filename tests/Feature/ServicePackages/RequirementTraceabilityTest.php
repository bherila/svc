<?php

namespace Tests\Feature\ServicePackages;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every requirement in docs/service-packages.md has a test that names it.
 *
 * Proves coverage exists, not that it is meaningful: the post-build review
 * judges each test against its requirement. PHP tests name requirements with
 * `#[Requirement('PKG-1')]`; Vitest and Playwright tests put `[PKG-1]` in the
 * test title.
 */
final class RequirementTraceabilityTest extends TestCase
{
    /**
     * Requirements whose slice is not built yet. Each slice removes its own
     * entries; the test refuses an entry that already has a test, so the list
     * can only shrink.
     *
     * @var list<string>
     */
    private const PENDING = [
        // Slice 6: version migration.
        'MIG-1', 'MIG-2', 'MIG-3', 'MIG-4', 'MIG-5',
    ];

    public function test_every_requirement_has_a_test_and_every_named_requirement_exists(): void
    {
        $documented = $this->documented();
        $named = $this->named();

        $this->assertNotEmpty($documented, 'No requirements were read from docs/service-packages.md.');
        $this->assertSame([], array_values(array_diff(self::PENDING, $documented)), 'PENDING lists a requirement the spec does not have.');

        $unknown = array_values(array_diff(array_keys($named), $documented));
        sort($unknown);
        $this->assertSame([], $unknown, "Tests name requirements the spec does not have:\n".$this->where($named, $unknown));

        $untested = array_values(array_diff($documented, array_keys($named), self::PENDING));
        $this->assertSame([], $untested, 'These requirements have no test that names them.');

        $built = array_values(array_intersect(self::PENDING, array_keys($named)));
        $this->assertSame([], $built, 'These requirements now have tests; remove them from PENDING.');
    }

    /** @return list<string> */
    private function documented(): array
    {
        $spec = (string) file_get_contents(dirname(__DIR__, 3).'/docs/service-packages.md');
        preg_match_all('/\*\*([A-Z]+-\d+)\b/', $spec, $matches);
        $ids = array_values(array_unique($matches[1]));
        sort($ids);

        return $ids;
    }

    /** @return array<string, list<string>> requirement id => files naming it */
    private function named(): array
    {
        $root = dirname(__DIR__, 3);
        $named = [];
        foreach ([[$root.'/tests', '/\.php$/'], [$root.'/resources/js', '/\.test\.tsx?$/'], [$root.'/scripts', '/\.(m?js|ts)$/']] as [$dir, $pattern]) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (! $file instanceof SplFileInfo || ! preg_match($pattern, $file->getFilename()) || $file->getRealPath() === __FILE__) {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                $ids = [];
                if (str_ends_with($file->getFilename(), '.php')) {
                    preg_match_all('/#\[Requirement\(([^)]*)\)\]/', $source, $attributes);
                    foreach ($attributes[1] as $arguments) {
                        preg_match_all("/'([A-Z]+-\\d+)'/", $arguments, $quoted);
                        array_push($ids, ...$quoted[1]);
                    }
                } else {
                    preg_match_all('/\[([A-Z]+-\d+)\]/', $source, $titles);
                    $ids = $titles[1];
                }
                foreach (array_unique($ids) as $id) {
                    $named[$id][] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        return $named;
    }

    /**
     * @param  array<string, list<string>>  $named
     * @param  list<string>  $ids
     */
    private function where(array $named, array $ids): string
    {
        return implode("\n", array_map(static fn (string $id): string => $id.': '.implode(', ', $named[$id]), $ids));
    }
}
