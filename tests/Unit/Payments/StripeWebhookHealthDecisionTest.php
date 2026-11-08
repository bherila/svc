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
    private function decide(array $endpoints, ?string $stored = 'we_1', ?string $environment = 'live', string $url = self::URL): WebhookHealth
    {
        return StripeWebhookHealthDecision::decide(self::REQUIRED, [$url], $environment, $stored, $endpoints);
    }

    public function test_no_endpoint_at_our_url_is_not_found_whatever_else_the_account_has(): void
    {
        $health = $this->decide([self::endpoint('we_other', 'https://elsewhere.example.test/hook', ['*'])], null);

        $this->assertSame(WebhookHealthStatus::NotFound, $health->status);
        $this->assertSame([], $health->missing);
        $this->assertNull($health->endpointId);
        $this->assertSame(WebhookHealthStatus::NotFound, $this->decide([], null)->status);
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

    public function test_the_legacy_refund_name_does_not_stand_in_for_the_current_one(): void
    {
        $health = $this->decide([self::endpoint('we_1', self::URL, ['a.one', 'b.two', 'charge.refund.updated'])]);

        $this->assertSame(WebhookHealthStatus::MissingEvents, $health->status);
        $this->assertSame(['refund.updated'], $health->missing);
        $this->assertSame(['refund.updated'], StripeWebhookHealthDecision::missing(self::REQUIRED, ['a.one', 'b.two', 'charge.refund.updated']));
        $this->assertSame([], StripeWebhookHealthDecision::missing(self::REQUIRED, ['a.one', 'b.two', 'refund.updated']));
    }

    public function test_a_disabled_endpoint_is_disabled_even_when_fully_subscribed(): void
    {
        $health = $this->decide([self::endpoint('we_1', self::URL, ['*'], 'disabled')]);

        $this->assertSame(WebhookHealthStatus::Disabled, $health->status);
        $this->assertSame('we_1', $health->endpointId);
    }

    public function test_only_the_stored_endpoint_is_judged_whatever_its_twins_say(): void
    {
        $endpoints = [self::endpoint('we_a', self::URL, ['*']), self::endpoint('we_b', self::URL, ['a.one']), self::endpoint('we_off', self::URL, ['*'], 'disabled')];

        $this->assertSame(WebhookHealthStatus::MissingEvents, $this->decide($endpoints, 'we_b')->status);
        $this->assertSame('we_b', $this->decide($endpoints, 'we_b')->endpointId);
        $this->assertSame(WebhookHealthStatus::Healthy, $this->decide($endpoints, 'we_a')->status);
        $this->assertSame(WebhookHealthStatus::Disabled, $this->decide($endpoints, 'we_off')->status);
    }

    public function test_a_deleted_stored_endpoint_is_an_identity_problem_even_when_another_endpoint_has_its_url(): void
    {
        $health = $this->decide([self::endpoint('we_other', self::URL, ['*'])], 'we_deleted');

        $this->assertSame(WebhookHealthStatus::EndpointMissing, $health->status);
        $this->assertNull($health->endpointId, 'No event-patch fix may be offered for an endpoint that is not the stored one.');
        $this->assertSame([], $health->missing);
        $this->assertSame([], $health->subscribed);
        $this->assertSame(WebhookHealthStatus::EndpointMissing, $this->decide([], 'we_deleted')->status);
        $this->assertSame(WebhookHealthStatus::EndpointMissing, $this->decide([self::endpoint('we_else', 'https://elsewhere.example.test/hook', ['*'])], 'we_deleted')->status);
    }

    public function test_a_stored_endpoint_moved_to_another_url_is_not_found_not_healthy(): void
    {
        $health = $this->decide([self::endpoint('we_1', 'https://elsewhere.example.test/hook', ['*']), self::endpoint('we_twin', self::URL, ['*'])]);

        $this->assertSame(WebhookHealthStatus::NotFound, $health->status);
        $this->assertNull($health->endpointId);
    }

    public function test_a_connection_with_no_stored_endpoint_cannot_confirm_a_url_match(): void
    {
        foreach ([[self::endpoint('we_1', self::URL, ['*'])], [self::endpoint('we_1', self::URL, ['*'], 'disabled')], [self::endpoint('we_1', self::URL, ['*'], 'enabled', false)]] as $endpoints) {
            $health = $this->decide($endpoints, null);

            $this->assertSame(WebhookHealthStatus::EndpointUnverified, $health->status);
            $this->assertNull($health->endpointId);
            $this->assertSame([], $health->missing);
        }
        $this->assertSame(WebhookHealthStatus::NotFound, $this->decide([self::endpoint('we_1', 'https://elsewhere.example.test/hook', ['*'])], null)->status);
        $this->assertSame(WebhookHealthStatus::NotFound, $this->decide([], null)->status);
    }

    public function test_an_expected_url_that_is_not_a_url_matches_nothing_not_even_another_that_is_not_one(): void
    {
        $health = StripeWebhookHealthDecision::decide(self::REQUIRED, ['nonsense'], 'live', 'we_1', [
            self::endpoint('we_1', 'also nonsense', ['*']),
        ]);

        $this->assertSame(WebhookHealthStatus::NotFound, $health->status);
    }

    public function test_expected_urls_are_normalised_too(): void
    {
        $health = StripeWebhookHealthDecision::decide(self::REQUIRED, ['HTTPS://SVC.example.test:443/api/webhooks/stripe/token0/'], 'live', 'we_1', [
            self::endpoint('we_1', self::URL, ['*']),
        ]);

        $this->assertSame(WebhookHealthStatus::Healthy, $health->status);
    }

    public function test_an_unknown_key_mode_is_never_a_mismatch(): void
    {
        foreach ([true, false] as $live) {
            foreach ([null, 'sandbox', ''] as $environment) {
                $this->assertSame(WebhookHealthStatus::Healthy, $this->decide([self::endpoint('we_1', self::URL, ['*'], 'enabled', $live)], 'we_1', $environment)->status);
            }
        }
    }

    public function test_the_wrong_mode_is_its_own_result(): void
    {
        $endpoint = self::endpoint('we_1', self::URL, ['*'], 'enabled', false);

        $this->assertSame(WebhookHealthStatus::ModeMismatch, $this->decide([$endpoint], 'we_1', 'live')->status);
        $this->assertSame(WebhookHealthStatus::Healthy, $this->decide([$endpoint], 'we_1', 'test')->status);
        $this->assertSame(WebhookHealthStatus::Healthy, $this->decide([$endpoint], 'we_1', null)->status);
        $this->assertSame(WebhookHealthStatus::ModeMismatch, $this->decide([self::endpoint('we_1', self::URL, ['*'], 'enabled', true)], 'we_1', 'test')->status);
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
        $health = StripeWebhookHealthDecision::decide(self::REQUIRED, [self::URL, 'https://svc.example.test/api/webhooks/stripe'], 'live', 'we_legacy', [
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
        foreach ([WebhookHealthStatus::MissingEvents, WebhookHealthStatus::Disabled, WebhookHealthStatus::NotFound, WebhookHealthStatus::EndpointMissing, WebhookHealthStatus::EndpointUnverified, WebhookHealthStatus::ModeMismatch, WebhookHealthStatus::PermissionDenied, WebhookHealthStatus::KeyRejected] as $status) {
            $this->assertSame('warning', $status->severity());
        }
    }
}
