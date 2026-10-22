<?php

namespace Tests\Unit;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * CI runs each database lane as several shards. A shard plan
 * that drops a file is a silent hole in the gate: every shard passes and the dropped tests
 * never run. These tests pin that scripts/ci/phpunit-shard.php assigns every file PHPUnit
 * lists to exactly one shard, deterministically, and that a shard's configuration differs
 * from phpunit.xml only in its file list and absolute paths.
 */
class PhpunitShardTest extends TestCase
{
    private string $root;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = dirname(__DIR__, 2);
        $this->scratch = sys_get_temp_dir().'/phpunit-shard-'.bin2hex(random_bytes(8));
        mkdir($this->scratch, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratch.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->scratch);

        parent::tearDown();
    }

    /**
     * @param  list<string>  $arguments
     */
    private function shard(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', $this->root.'/scripts/ci/phpunit-shard.php', ...$arguments], $this->root);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    /**
     * @return list<array{files: list<string>, tests: int}>
     */
    private function plan(int $total, ?string $listing = null): array
    {
        $process = $this->shard(['--plan', (string) $total, ...($listing === null ? [] : ['--listing='.$listing])]);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        /** @var list<array{files: list<string>, tests: int}> $plan */
        $plan = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $plan;
    }

    /**
     * @param  array<string, int>  $files  path => test count
     */
    private function listing(array $files): string
    {
        $xml = '<?xml version="1.0"?><testSuite xmlns="https://xml.phpunit.de/testSuite"><tests>';
        foreach ($files as $file => $tests) {
            $xml .= '<testClass name="X" file="'.$this->root.'/'.$file.'">'
                .str_repeat('<testMethod id="X::t" name="t"/>', $tests)
                .'</testClass>';
        }
        $path = $this->scratch.'/listing-'.count($files).'.xml';
        file_put_contents($path, $xml.'</tests></testSuite>');

        return $path;
    }

    /**
     * Every file PHPUnit itself would run under phpunit.xml, relative to the repository.
     *
     * @return list<string>
     */
    private function phpunitFiles(): array
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', $this->root.'/vendor/bin/phpunit', '--configuration', $this->root.'/phpunit.xml', '--list-test-files'], $this->root);
        $process->setTimeout(120);
        $process->mustRun();

        preg_match_all('/^ - (.+\.php)$/m', $process->getOutput(), $matches);
        $files = array_map(fn (string $file): string => substr($file, strlen($this->root) + 1), $matches[1]);
        sort($files);

        return array_values(array_unique($files));
    }

    public function test_the_live_plan_runs_every_test_file_phpunit_lists_exactly_once(): void
    {
        $expected = $this->phpunitFiles();
        $this->assertGreaterThan(100, count($expected));

        foreach ([4] as $total) {
            $plan = $this->plan($total);
            $this->assertCount($total, $plan);

            $assigned = array_merge(...array_column($plan, 'files'));
            $this->assertSame(count($assigned), count(array_unique($assigned)), "A file is in two of {$total} shards.");
            sort($assigned);
            $this->assertSame($expected, $assigned, "{$total} shards must cover exactly the files PHPUnit lists.");
        }
    }

    public function test_the_partition_is_deterministic_and_balanced_by_test_count(): void
    {
        $files = [];
        foreach (range(1, 40) as $i) {
            $files[sprintf('tests/Unit/F%02dTest.php', $i)] = ($i * 7) % 13 + 1;
        }
        $files['tests/Feature/HeavyTest.php'] = 30;
        $listing = $this->listing($files);

        $plan = $this->plan(4, $listing);
        $this->assertSame($plan, $this->plan(4, $listing), 'The same tree must always give the same shards.');

        $loads = array_column($plan, 'tests');
        $this->assertSame(array_sum($files), array_sum($loads));
        // Greedy largest-first keeps the spread within the heaviest single file.
        $this->assertLessThanOrEqual(max($files), max($loads) - min($loads));

        $assigned = array_merge(...array_column($plan, 'files'));
        sort($assigned);
        $expected = array_keys($files);
        sort($expected);
        $this->assertSame($expected, $assigned);
    }

    public function test_a_shard_config_is_phpunit_xml_with_only_its_file_list_replaced(): void
    {
        $listing = $this->listing(['tests/Unit/ATest.php' => 3, 'tests/Unit/BTest.php' => 2, 'tests/Feature/CTest.php' => 1]);
        $plan = $this->plan(2, $listing);

        foreach ([1, 2] as $shard) {
            $output = $this->scratch."/shard-{$shard}.xml";
            $process = $this->shard([(string) $shard, '2', $output, '--listing='.$listing]);
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString("Shard {$shard} of 2", $process->getOutput());

            $config = new DOMDocument;
            $config->load($output);
            $suites = $config->getElementsByTagName('testsuite');
            $this->assertSame(1, $suites->length);

            $files = [];
            foreach ($suites->item(0)->childNodes as $node) {
                if ($node instanceof DOMElement) {
                    $this->assertSame('file', $node->tagName);
                    $files[] = $node->textContent;
                }
            }
            $this->assertSame(array_map(fn (string $file): string => $this->root.'/'.$file, $plan[$shard - 1]['files']), $files);

            $this->assertSame($this->root.'/vendor/autoload.php', $config->documentElement->getAttribute('bootstrap'));
            $this->assertSame($this->root.'/app', $config->getElementsByTagName('source')->item(0)->getElementsByTagName('directory')->item(0)->textContent);

            $this->assertSame($this->withoutTestSuites($this->root.'/phpunit.xml'), $this->withoutTestSuites($output));
        }
    }

    public function test_it_fails_closed(): void
    {
        $listing = $this->listing(['tests/Unit/ATest.php' => 1, 'tests/Unit/BTest.php' => 1]);

        foreach ([['0', '2'], ['3', '2'], ['1', '0'], ['1x', '2'], ['1', '2.5']] as [$shard, $total]) {
            $process = $this->shard([$shard, $total, $this->scratch.'/out.xml', '--listing='.$listing]);
            $this->assertSame(1, $process->getExitCode(), "shard {$shard} of {$total}");
        }

        $tooMany = $this->shard(['3', '3', $this->scratch.'/out.xml', '--listing='.$listing]);
        $this->assertSame(1, $tooMany->getExitCode());
        $this->assertStringContainsString('is empty', $tooMany->getErrorOutput());

        $empty = $this->shard(['1', '1', $this->scratch.'/out.xml', '--listing='.$this->listing([])]);
        $this->assertSame(1, $empty->getExitCode());
        $this->assertStringContainsString('listed no test files', $empty->getErrorOutput());

        $this->assertFileDoesNotExist($this->scratch.'/out.xml');
    }

    public function test_a_temporary_shard_config_can_list_its_selected_files_without_running_them(): void
    {
        $output = $this->scratch.'/live.xml';
        $process = $this->shard(['1', '4', $output]);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', $this->root.'/vendor/bin/phpunit', '--configuration', $output, '--list-test-files'], $this->root);
        $process->setTimeout(120);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $config = new DOMDocument;
        $config->load($output);
        $files = [];
        foreach ($config->getElementsByTagName('testsuite')->item(0)->getElementsByTagName('file') as $file) {
            $files[] = $file->textContent;
        }
        preg_match_all('/^ - (.+\.php)$/m', $process->getOutput(), $matches);
        sort($files);
        sort($matches[1]);
        $this->assertNotEmpty($files);
        $this->assertSame($files, $matches[1]);
    }

    private function withoutTestSuites(string $path): string
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;
        $document->load($path);
        if ($path === $this->root.'/phpunit.xml') {
            $document->documentElement->setAttribute('bootstrap', $this->root.'/vendor/autoload.php');
            $document->documentElement->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:noNamespaceSchemaLocation', $this->root.'/vendor/phpunit/phpunit/phpunit.xsd');
            $document->getElementsByTagName('source')->item(0)->getElementsByTagName('directory')->item(0)->nodeValue = $this->root.'/app';
        }
        $suites = $document->getElementsByTagName('testsuites')->item(0);
        $suites?->parentNode?->removeChild($suites);

        return (string) $document->C14N();
    }
}
