<?php

namespace Tests\Unit\Payments;

use App\Models\WorkspacePaymentConnection;
use App\Services\Billing\StripeWebhookService;
use App\Services\Payments\StripeKeyAdapter;
use App\Services\Payments\StripeWebhookHealthService;
use App\Services\Payments\WebhookHealthPresenter;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Support\Payments\WebhookHealthStatus;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(WebhookHealthPresenter::class)]
#[CoversClass(StripeWebhookHealthService::class)]
final class WebhookHealthPresenterTest extends TestCase
{
    private function presenter(): WebhookHealthPresenter
    {
        return new WebhookHealthPresenter(new StripeWebhookHealthService(new WorkspacePaymentConnections, new StripeKeyAdapter));
    }

    /** @param  list<string>  $missing */
    private function row(?WebhookHealthStatus $status, array $missing = [], string $origin = 'entered', string $processor = 'stripe', bool $reused = false, ?string $secret = 'rk_live_synthetic_presenter_a1b2'): WorkspacePaymentConnection
    {
        config(['app.url' => 'https://svc.example.test']);
        $row = new WorkspacePaymentConnection;
        $row->forceFill([
            'processor' => $processor,
            'origin' => $origin,
            'endpoint_token' => str_repeat('t', 40),
            'secret' => $secret,
            'reuses_connection_id' => $reused ? 7 : null,
            'revoked_at' => null,
            'webhook_check_status' => $status?->value,
            'webhook_check_missing' => $missing,
            'webhook_checked_at' => $status === null ? null : Carbon::parse('2026-11-07 10:00:00', 'UTC'),
        ]);

        return $row;
    }

    public function test_a_healthy_connection_counts_the_required_events(): void
    {
        $view = $this->presenter()->present($this->row(WebhookHealthStatus::Healthy), true, '/check', '/fix');

        $this->assertNotNull($view);
        $this->assertSame('ok', $view['severity']);
        $this->assertSame('Webhook: all '.count(StripeWebhookService::REQUIRED_EVENTS).' events subscribed', $view['summary']);
        $this->assertNull($view['detail']);
        $this->assertSame([], $view['missing']);
        $this->assertNull($view['fix']);
        $this->assertSame('https://svc.example.test/api/webhooks/stripe/'.str_repeat('t', 40), $view['endpoint_url']);
        $this->assertSame('/check', $view['check_url']);
        $this->assertSame('2026-11-07T10:00:00+00:00', $view['checked_at']);
    }

    public function test_missing_events_are_listed_and_offer_the_add_fix_with_its_confirmation(): void
    {
        $view = $this->presenter()->present($this->row(WebhookHealthStatus::MissingEvents, ['a.one', 'b.two']), true, '/check', '/fix');

        $this->assertNotNull($view);
        $this->assertSame('warning', $view['severity']);
        $this->assertSame('Missing: a.one, b.two', $view['summary']);
        $this->assertSame(['a.one', 'b.two'], $view['missing']);
        $this->assertIsString($view['detail']);
        $this->assertSame('add_events', $view['fix']['action'] ?? null);
        $this->assertSame('/fix', $view['fix']['url'] ?? null);
        $this->assertSame('Add missing events', $view['fix']['label'] ?? null);
        $this->assertStringContainsString('a.one, b.two', $view['fix']['confirm_body'] ?? '');
        $this->assertStringContainsString('Webhook Endpoints: Write', $view['fix']['confirm_body'] ?? '');
    }

    public function test_the_confirmation_names_exactly_what_will_be_added(): void
    {
        $view = $this->presenter()->present($this->row(WebhookHealthStatus::MissingEvents, ['a.one', 'b.two']), true, '/check', '/fix');

        $this->assertSame('Add the missing events to the Stripe webhook?', $view['fix']['confirm_title'] ?? null);
        $this->assertSame('SVC will subscribe this workspace\'s endpoint to a.one, b.two. Events it is already subscribed to are kept, and no other endpoint is touched. The restricted key needs Webhook Endpoints: Write.', $view['fix']['confirm_body'] ?? null);
    }

    public function test_a_stored_list_is_cleaned_to_event_names(): void
    {
        $view = $this->presenter()->present($this->row(WebhookHealthStatus::MissingEvents, [0 => 'a.one', 1 => 7, 2 => 'b.two', 3 => null]), true, '/check', '/fix');

        $this->assertNotNull($view);
        $this->assertSame(['a.one', 'b.two'], $view['missing']);
        $this->assertSame('Missing: a.one, b.two', $view['summary']);
    }

    public function test_the_signing_and_key_secrets_are_encrypted_at_rest(): void
    {
        $row = $this->row(WebhookHealthStatus::Healthy);
        $row->forceFill(['webhook_secret' => 'whsec_synthetic_presenter']);
        $raw = $row->getAttributes();

        $this->assertSame('encrypted', $row->getCasts()['secret'] ?? null);
        $this->assertSame('encrypted', $row->getCasts()['webhook_secret'] ?? null);
        $this->assertNotSame('rk_live_synthetic_presenter_a1b2', $raw['secret']);
        $this->assertNotSame('whsec_synthetic_presenter', $raw['webhook_secret']);
        $this->assertSame('whsec_synthetic_presenter', $row->webhookSecret());
    }

    public function test_a_missing_endpoint_offers_to_create_one_and_says_the_secret_is_replaced(): void
    {
        $view = $this->presenter()->present($this->row(WebhookHealthStatus::NotFound), true, '/check', '/fix');

        $this->assertNotNull($view);
        $this->assertSame('No webhook endpoint found for this connection', $view['summary']);
        $this->assertSame('create', $view['fix']['action'] ?? null);
        $this->assertSame('Create endpoint', $view['fix']['label'] ?? null);
        $this->assertStringContainsString('signing secret', $view['fix']['confirm_body'] ?? '');
    }

    public function test_every_other_result_has_words_a_severity_and_no_fix(): void
    {
        $expected = [
            'disabled' => ['Webhook endpoint is disabled in Stripe', 'warning'],
            'mode_mismatch' => ['Webhook endpoint is in the other mode than this key', 'warning'],
            'permission_denied' => ['SVC cannot read this key\'s webhook endpoints', 'warning'],
            'key_rejected' => ['Stripe did not accept the saved key', 'warning'],
            'unreachable' => ['The webhook could not be checked', 'unknown'],
        ];
        foreach ($expected as $value => [$summary, $severity]) {
            $view = $this->presenter()->present($this->row(WebhookHealthStatus::from($value), ['ignored.when.not.missing']), true, '/check', '/fix');

            $this->assertNotNull($view, $value);
            $this->assertSame($summary, $view['summary'], $value);
            $this->assertSame($severity, $view['severity'], $value);
            $this->assertIsString($view['detail'], $value);
            $this->assertNull($view['fix'], $value);
            $this->assertSame([], $view['missing'], $value);
        }
    }

    public function test_a_connection_never_checked_says_so(): void
    {
        $view = $this->presenter()->present($this->row(null), true, '/check', '/fix');

        $this->assertNotNull($view);
        $this->assertSame('Webhook not checked yet', $view['summary']);
        $this->assertSame('unknown', $view['severity']);
        $this->assertNull($view['checked_at']);
        $this->assertNull($view['fix']);
    }

    public function test_someone_who_cannot_manage_sees_neither_url_nor_buttons(): void
    {
        $view = $this->presenter()->present($this->row(WebhookHealthStatus::MissingEvents, ['a.one']), false, '/check', '/fix');

        $this->assertNotNull($view);
        $this->assertSame('Missing: a.one', $view['summary']);
        $this->assertNull($view['endpoint_url']);
        $this->assertNull($view['check_url']);
        $this->assertNull($view['fix']);
    }

    public function test_no_fix_without_a_fix_url(): void
    {
        $view = $this->presenter()->present($this->row(WebhookHealthStatus::NotFound), true, '/check', null);

        $this->assertNotNull($view);
        $this->assertNull($view['fix']);
    }

    public function test_an_unknown_stored_status_reads_as_never_checked(): void
    {
        $row = $this->row(WebhookHealthStatus::Healthy);
        $row->webhook_check_status = 'from_the_future';

        $view = $this->presenter()->present($row, true, '/check', '/fix');

        $this->assertNotNull($view);
        $this->assertSame('Webhook not checked yet', $view['summary']);
    }

    public function test_only_a_stripe_connection_with_its_own_key_has_a_health_line(): void
    {
        $this->assertNull($this->presenter()->present($this->row(WebhookHealthStatus::Healthy, processor: 'square'), true, '/c', '/f'));
        $this->assertNull($this->presenter()->present($this->row(WebhookHealthStatus::Healthy, reused: true), true, '/c', '/f'));
        $this->assertNull($this->presenter()->present($this->row(WebhookHealthStatus::Healthy, secret: null), true, '/c', '/f'));
        $revoked = $this->row(WebhookHealthStatus::Healthy);
        $revoked->revoked_at = Carbon::now();
        $this->assertNull($this->presenter()->present($revoked, true, '/c', '/f'));
    }

    public function test_the_expected_urls(): void
    {
        $service = new StripeWebhookHealthService(new WorkspacePaymentConnections, new StripeKeyAdapter);

        $this->assertSame(['https://svc.example.test/api/webhooks/stripe/'.str_repeat('t', 40)], $service->expectedUrls($this->row(null)));
        $this->assertSame([
            'https://svc.example.test/api/webhooks/stripe/'.str_repeat('t', 40),
            'https://svc.example.test/api/webhooks/stripe',
        ], $service->expectedUrls($this->row(null, origin: 'environment')));
    }

    public function test_checking_a_row_that_cannot_be_checked_asks_nobody(): void
    {
        $service = new StripeWebhookHealthService(new WorkspacePaymentConnections, new StripeKeyAdapter);

        $this->assertSame(WebhookHealthStatus::Unreachable, $service->check($this->row(null, processor: 'square'))->status);
        $this->assertSame(WebhookHealthStatus::Unreachable, $service->check($this->row(null, reused: true))->status);
    }
}
