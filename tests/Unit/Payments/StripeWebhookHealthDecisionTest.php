<?php

namespace Tests\Unit\Payments;

use App\Support\Payments\StripeWebhookHealthDecision;
use App\Support\Payments\WebhookHealth;
use App\Support\Payments\WebhookHealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(StripeWebhookHealthDecision::class)]
final class StripeWebhookHealthDecisionTest extends TestCase
{
    private const array REQUIRED = ['a.one', 'b.two', 'refund.updated'];

    private const array STAND_INS = ['refund.updated' => 'charge.refund.updated'];

    private const string URL = 'https://svc.example.test/api/webhooks/stripe/token0';

    /**
     * @param  list<string>  $events
     * @return array{id: string, url: string, status: string, enabled_events: list<string>, livemode: bool}
     */
    private static function endpoint(string $id, string $url, array $events, string $status = 'enabled', bool $live = true): array
    {
        return ['id' => $id, 'url' => $url, 'status' => $status, 'enabled_events' => $events, 'livemode' => $live];
    }

    /** @param list<array{id: string, url: string, status: string, enabled_events: list<string>, livemode: bool}> $endpoints */
    private function decide(array $endpoints, ?string $stored = null, ?string $environment = 'live', string $url = self::URL): WebhookHealth
    {
        return StripeWebhookHealthDecision::decide(self::REQUIRED, self::STAND_INS, [$url], $environment, $stored, $endpoints);
    }

    public function test_no_endpoint_at_our_url_is_not_found_whatever_else_the_account_has(): void
    {
        $health = $this->decide([self::endpoint('we_other', 'https://elsewhere.example.test/hook', ['*'])]);

        $this->assertSame(WebhookHealthStatus::NotFound, $health->status);
        $this->assertSame([], $health->missing);
        $this->assertNull($health->endpointId);
        $this->assertSame(WebhookHealthStatus::NotFound, $this->decide([])->status);
    }

    public function test_a_subscribed_enabled_endpoint_is_healthy(): void
    {
        $health = $this->decide([self::endpoint('we_1', self::URL, ['b.two', 'a.one', 'refund.updated', 'extra.thing'])]);

        $this->assertSame(WebhookHealthStatus::Healthy, $health->status);
        $this->assertSame([], $health->missing);
        $this->assertSame('we_1', $health->endpointId);
    }

    public function test_missing_events_are_listed_in_the_required_order(): void
    {
        $health = $this->decide([self::endpoint('we_1', self::URL, ['b.two'])]);

        $this->assertSame(WebhookHealthStatus::MissingEvents, $health->status);
        $this->assertSame(['a.one', 'refund.updated'], $health->missing);
        $this->assertSame(['b.two'], $health->subscribed);
    }

    public function test_a_wildcard_subscribes_to_everything(): void
    {
        $this->assertSame(WebhookHealthStatus::Healthy, $this->decide([self::endpoint('we_1', self::URL, ['*'])])->status);
    }

    public function test_the_legacy_name_stands_in_for_the_current_one(): void
    {
        $this->assertSame(WebhookHealthStatus::Healthy, $this->decide([self::endpoint('we_1', self::URL, ['a.one', 'b.two', 'charge.refund.updated'])])->status);
        $this->assertSame([], StripeWebhookHealthDecision::missing(self::REQUIRED, self::STAND_INS, ['a.one', 'b.two', 'charge.refund.updated']));
        $this->assertSame(['refund.updated'], StripeWebhookHealthDecision::missing(self::REQUIRED, [], ['a.one', 'b.two', 'charge.refund.updated']));
    }

    public function test_a_disabled_endpoint_is_disabled_even_when_fully_subscribed(): void
    {
        $health = $this->decide([self::endpoint('we_1', self::URL, ['*'], 'disabled')]);

        $this->assertSame(WebhookHealthStatus::Disabled, $health->status);
        $this->assertSame('we_1', $health->endpointId);
    }

    public function test_an_enabled_twin_wins_over_a_disabled_one_unless_ours_is_the_stored_id(): void
    {
        $endpoints = [self::endpoint('we_off', self::URL, ['*'], 'disabled'), self::endpoint('we_on', self::URL, ['a.one'])];

        $this->assertSame('we_on', $this->decide($endpoints)->endpointId);
        $this->assertSame(WebhookHealthStatus::MissingEvents, $this->decide($endpoints)->status);
        $this->assertSame('we_off', $this->decide($endpoints, 'we_off')->endpointId);
        $this->assertSame(WebhookHealthStatus::Disabled, $this->decide($endpoints, 'we_off')->status);
    }

    public function test_the_stored_endpoint_is_judged_even_when_another_enabled_twin_is_listed_first(): void
    {
        $endpoints = [self::endpoint('we_a', self::URL, ['a.one']), self::endpoint('we_b', self::URL, ['*'])];

        $health = $this->decide($endpoints, 'we_b');

        $this->assertSame('we_b', $health->endpointId);
        $this->assertSame(WebhookHealthStatus::Healthy, $health->status);
        $this->assertSame('we_a', $this->decide($endpoints, null)->endpointId);
        $this->assertSame('we_a', $this->decide($endpoints, 'we_unknown')->endpointId);
    }

    public function test_the_first_listed_match_is_judged_when_none_is_enabled_and_another_endpoint_comes_first(): void
    {
        $endpoints = [self::endpoint('we_elsewhere', 'https://elsewhere.example.test/hook', ['*']), self::endpoint('we_off', self::URL, ['*'], 'disabled')];

        $this->assertSame('we_off', $this->decide($endpoints)->endpointId);
    }

    public function test_an_expected_url_that_is_not_a_url_matches_nothing_not_even_another_that_is_not_one(): void
    {
        $health = StripeWebhookHealthDecision::decide(self::REQUIRED, self::STAND_INS, ['nonsense'], 'live', null, [
            self::endpoint('we_1', 'also nonsense', ['*']),
        ]);

        $this->assertSame(WebhookHealthStatus::NotFound, $health->status);
    }

    public function test_expected_urls_are_normalised_too(): void
    {
        $health = StripeWebhookHealthDecision::decide(self::REQUIRED, self::STAND_INS, ['HTTPS://SVC.example.test:443/api/webhooks/stripe/token0/'], 'live', null, [
            self::endpoint('we_1', self::URL, ['*']),
        ]);

        $this->assertSame(WebhookHealthStatus::Healthy, $health->status);
    }

    public function test_an_unknown_key_mode_is_never_a_mismatch(): void
    {
        foreach ([true, false] as $live) {
            foreach ([null, 'sandbox', ''] as $environment) {
                $this->assertSame(WebhookHealthStatus::Healthy, $this->decide([self::endpoint('we_1', self::URL, ['*'], 'enabled', $live)], null, $environment)->status);
            }
        }
    }

    public function test_the_wrong_mode_is_its_own_result(): void
    {
        $endpoint = self::endpoint('we_1', self::URL, ['*'], 'enabled', false);

        $this->assertSame(WebhookHealthStatus::ModeMismatch, $this->decide([$endpoint], null, 'live')->status);
        $this->assertSame(WebhookHealthStatus::Healthy, $this->decide([$endpoint], null, 'test')->status);
        $this->assertSame(WebhookHealthStatus::Healthy, $this->decide([$endpoint], null, null)->status);
        $this->assertSame(WebhookHealthStatus::ModeMismatch, $this->decide([self::endpoint('we_1', self::URL, ['*'], 'enabled', true)], null, 'test')->status);
    }

    #[DataProvider('sameUrls')]
    public function test_urls_are_compared_by_scheme_host_and_path(string $theirs): void
    {
        $this->assertSame(WebhookHealthStatus::Healthy, $this->decide([self::endpoint('we_1', $theirs, ['*'])])->status);
    }

    /** @return array<string, array{0: string}> */
    public static function sameUrls(): array
    {
        return [
            'identical' => [self::URL],
            'scheme and host in capitals' => ['HTTPS://SVC.Example.TEST/api/webhooks/stripe/token0'],
            'a trailing slash' => [self::URL.'/'],
            'the default port' => ['https://svc.example.test:443/api/webhooks/stripe/token0'],
            'a query and a fragment' => [self::URL.'?x=1#frag'],
        ];
    }

    #[DataProvider('otherUrls')]
    public function test_a_different_scheme_host_port_or_path_is_another_endpoint(string $theirs): void
    {
        $this->assertSame(WebhookHealthStatus::NotFound, $this->decide([self::endpoint('we_1', $theirs, ['*'])])->status);
    }

    /** @return array<string, array{0: string}> */
    public static function otherUrls(): array
    {
        return [
            'http' => ['http://svc.example.test/api/webhooks/stripe/token0'],
            'another host' => ['https://svc2.example.test/api/webhooks/stripe/token0'],
            'another port' => ['https://svc.example.test:8443/api/webhooks/stripe/token0'],
            'another token' => ['https://svc.example.test/api/webhooks/stripe/token1'],
            'the legacy path' => ['https://svc.example.test/api/webhooks/stripe'],
            'not a URL' => ['not a url'],
            'another scheme' => ['ftp://svc.example.test/api/webhooks/stripe/token0'],
        ];
    }

    public function test_normalising(): void
    {
        $this->assertSame('http://h.test/p', StripeWebhookHealthDecision::normalize('http://H.test:80/p/'));
        $this->assertSame('https://h.test:8080/', StripeWebhookHealthDecision::normalize('https://h.test:8080'));
        $this->assertNull(StripeWebhookHealthDecision::normalize('/relative/path'));
        $this->assertNull(StripeWebhookHealthDecision::normalize(''));
        $this->assertNull(StripeWebhookHealthDecision::normalize('ftp://h.test/p'));
        $this->assertNull(StripeWebhookHealthDecision::normalize('mailto:a@h.test'));
        $this->assertSame('https://h.test/p', StripeWebhookHealthDecision::normalize("  https://h.test/p \n"));
        $this->assertSame('http://h.test/p', StripeWebhookHealthDecision::normalize('http://h.test/p'));
        $this->assertSame('https://h.test/p', StripeWebhookHealthDecision::normalize('https://h.test/p'));
    }

    public function test_either_expected_url_matches(): void
    {
        $health = StripeWebhookHealthDecision::decide(self::REQUIRED, self::STAND_INS, [self::URL, 'https://svc.example.test/api/webhooks/stripe'], 'live', null, [
            self::endpoint('we_legacy', 'https://svc.example.test/api/webhooks/stripe', ['*']),
        ]);

        $this->assertSame(WebhookHealthStatus::Healthy, $health->status);
    }

    #[DataProvider('failures')]
    public function test_what_a_failed_call_means(?int $status, ?string $type, WebhookHealthStatus $expected): void
    {
        $this->assertSame($expected, StripeWebhookHealthDecision::failure($status, $type));
    }

    /** @return array<string, array{0: int|null, 1: string|null, 2: WebhookHealthStatus}> */
    public static function failures(): array
    {
        return [
            'a 403' => [403, 'invalid_request_error', WebhookHealthStatus::PermissionDenied],
            'a permission error' => [400, 'permission_error', WebhookHealthStatus::PermissionDenied],
            'a 401' => [401, null, WebhookHealthStatus::KeyRejected],
            'an authentication error' => [null, 'authentication_error', WebhookHealthStatus::KeyRejected],
            'no answer' => [null, null, WebhookHealthStatus::Unreachable],
            'a server error' => [500, 'api_error', WebhookHealthStatus::Unreachable],
            'a rate limit' => [429, 'rate_limit_error', WebhookHealthStatus::Unreachable],
        ];
    }

    public function test_severities(): void
    {
        $this->assertSame('ok', WebhookHealthStatus::Healthy->severity());
        $this->assertSame('unknown', WebhookHealthStatus::Unreachable->severity());
        foreach ([WebhookHealthStatus::MissingEvents, WebhookHealthStatus::Disabled, WebhookHealthStatus::NotFound, WebhookHealthStatus::ModeMismatch, WebhookHealthStatus::PermissionDenied, WebhookHealthStatus::KeyRejected] as $status) {
            $this->assertSame('warning', $status->severity());
        }
    }
}
