<?php

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** The Stripe test-mode lane stays advisory, parallel, safe without its secret, and out of the default suites. */
final class StripeTestModeWorkflowTest extends TestCase
{
    /** @return array<string, mixed> */
    private function workflow(): array
    {
        $parsed = Yaml::parseFile(dirname(__DIR__, 3).'/.github/workflows/stripe-test-mode.yml');
        $this->assertIsArray($parsed);

        return $parsed;
    }

    public function test_it_runs_on_billing_pull_requests_main_pushes_and_nightly(): void
    {
        $on = $this->workflow()['on'] ?? $this->workflow()[1] ?? [];
        $this->assertIsArray($on);
        $this->assertSame(['main'], $on['push']['branches']);
        $this->assertContains('app/Services/Billing/**', $on['pull_request']['paths']);
        $this->assertContains('tests/StripeLive/**', $on['pull_request']['paths']);
        $this->assertMatchesRegularExpression('/^\d+ \d+ \* \* \*$/', $on['schedule'][0]['cron']);
        $this->assertArrayHasKey('workflow_dispatch', $on);
    }

    public function test_it_is_not_a_need_of_the_required_ci_aggregate_and_needs_nothing_itself(): void
    {
        $tests = Yaml::parseFile(dirname(__DIR__, 3).'/.github/workflows/tests.yml');
        $this->assertIsArray($tests);
        foreach ($tests['jobs']['ci']['needs'] as $need) {
            $this->assertStringNotContainsString('stripe', $need);
        }
        $job = $this->workflow()['jobs']['stripe-test-mode'];
        $this->assertSame([], $job['needs'] ?? []);
    }

    public function test_it_skips_cleanly_without_the_secret_and_hands_it_only_to_the_steps_that_need_it(): void
    {
        $workflow = $this->workflow();
        $job = $workflow['jobs']['stripe-test-mode'];
        $this->assertArrayNotHasKey('env', $job);
        $this->assertArrayNotHasKey('continue-on-error', $job);

        $holders = [];
        foreach ($job['steps'] as $step) {
            if (isset($step['env']['STRIPE_TEST_SECRET_KEY'])) {
                $holders[] = $step['name'];
                $this->assertSame('${{ secrets.STRIPE_TEST_SECRET_KEY }}', $step['env']['STRIPE_TEST_SECRET_KEY']);
            }
            if ($step['name'] !== 'Check that the test key is available') {
                $this->assertSame("\${{ steps.key.outputs.present == 'true' }}", $step['if'], $step['name'].' must be skipped without the key.');
            }
        }
        $this->assertSame(['Check that the test key is available', 'Run the Stripe test-mode suite'], $holders);
        $this->assertStringContainsString('sk_test_*|rk_test_*', $job['steps'][0]['run']);
    }

    public function test_the_live_suite_is_outside_the_default_phpunit_configuration(): void
    {
        $default = file_get_contents(dirname(__DIR__, 3).'/phpunit.xml');
        $live = file_get_contents(dirname(__DIR__, 3).'/phpunit.stripe-live.xml');
        $this->assertIsString($default);
        $this->assertIsString($live);
        $this->assertStringNotContainsString('StripeLive', $default);
        $this->assertStringContainsString('<directory>tests/StripeLive</directory>', $live);
        $infection = file_get_contents(dirname(__DIR__, 3).'/infection.diff.json5');
        $this->assertIsString($infection);
        $this->assertStringNotContainsString('stripe-live', $infection);
    }
}
