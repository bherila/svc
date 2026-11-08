<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SessionCookieConfigurationTest extends TestCase
{
    public function test_an_https_application_url_makes_the_cookies_secure_http_only_and_lax(): void
    {
        $config = $this->sessionConfig(['APP_URL' => 'https://svc.example.test']);

        $this->assertTrue($config['secure']);
        $this->assertTrue($config['http_only']);
        $this->assertSame('lax', $config['same_site']);
        $this->assertFalse($config['partitioned']);
        $this->assertSame('/', $config['path']);
        $this->assertNull($config['domain']);
    }

    public function test_a_plain_http_local_checkout_is_not_forced_secure(): void
    {
        $this->assertFalse($this->sessionConfig(['APP_URL' => 'http://localhost:8000'])['secure']);
        $this->assertFalse($this->sessionConfig([])['secure']);
    }

    public function test_the_secure_flag_can_still_be_set_explicitly(): void
    {
        $this->assertFalse($this->sessionConfig(['APP_URL' => 'https://svc.example.test', 'SESSION_SECURE_COOKIE' => 'false'])['secure']);
        $this->assertTrue($this->sessionConfig(['APP_URL' => 'http://localhost', 'SESSION_SECURE_COOKIE' => 'true'])['secure']);
    }

    public function test_the_session_is_stored_server_side_and_expires(): void
    {
        $config = $this->sessionConfig([]);

        $this->assertSame('database', $config['driver']);
        $this->assertSame(120, $config['lifetime']);
        $this->assertSame('json', $config['serialization']);
    }

    /**
     * Evaluated in a clean process so only the variables given here exist: the suite's own
     * environment would otherwise decide the answer.
     *
     * @param  array<string, string>  $environment
     * @return array<string, mixed>
     */
    private function sessionConfig(array $environment): array
    {
        $root = dirname(__DIR__, 2);
        $inherited = [];
        foreach (array_keys(getenv()) as $name) {
            if (str_starts_with((string) $name, 'SESSION_') || $name === 'APP_URL' || $name === 'APP_NAME') {
                $inherited[$name] = false;
            }
        }
        $process = new Process(
            [PHP_BINARY, '-r', 'function storage_path(string $path = \'\'): string { return \'/tmp/\'.$path; } require $argv[1].\'/vendor/autoload.php\'; echo json_encode(require $argv[1].\'/config/session.php\');', $root],
            $root,
            $environment + $inherited,
        );
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
