<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Services\Billing\StripeWebhookService;
use App\Services\Payments\PaymentConnectionService;
use App\Services\Payments\PaymentCredentials;
use App\Services\Payments\StripeWebhookHealthService;
use App\Support\Payments\WebhookHealthStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Tests\Concerns\FakesStripeKeys;
use Tests\TestCase;

/**
 * Whether the Stripe account's webhook endpoint is subscribed to every event SVC
 * needs: checked on save, on demand and daily, recorded per connection and fixed
 * by an owner or admin. Nothing reaches Stripe; every key and id is synthetic.
 */
final class StripeWebhookHealthTest extends TestCase
{
    use FakesStripeKeys;
    use RefreshDatabase;

    private const string KEY_A = 'rk_live_synthetic_health_a_a1b2';

    private const string KEY_B = 'rk_live_synthetic_health_b_c3d4';

    private const string BASE = 'https://svc.example.test/api/webhooks/stripe';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://svc.example.test', 'services.stripe.secret_key' => null, 'services.stripe.webhook_secret' => null]);
        $this->fakeStripeKeys(self::stripeAnswers());
        if (! Route::has('svc.billing.stripe.webhook')) {
            require base_path('routes/billing.php');
        }
    }

    protected function tearDown(): void
    {
        $this->restoreStripeKeys();
        parent::tearDown();
    }

    // ---- when it runs ----

    public function test_saving_a_key_records_the_result_without_blocking_the_save(): void
    {
        [$owner, $workspace] = $this->tenant('save');

        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();

        $row = $this->activeFor($workspace);
        $this->assertNotNull($row);
        // SVC created the endpoint with every event it handles, so the check finds it whole.
        $this->assertSame('healthy', $row->webhook_check_status);
        $this->assertSame([], $row->webhook_check_missing);
        $this->assertNotNull($row->webhook_checked_at);
        $this->assertCount(1, $this->callsTo('get', '/v1/webhook_endpoints'));
        $this->assertSame(self::KEY_A, $this->callsTo('get', '/v1/webhook_endpoints')[0]['key']);
    }

    public function test_a_key_that_cannot_read_endpoints_still_saves_and_records_why_not(): void
    {
        [$owner, $workspace] = $this->tenant('noread');

        $this->actingAs($owner)->post($this->url($workspace), ['secret' => 'rk_live_synthetic_no_webhook_read_zz'])->assertNoContent();

        $this->assertSame('permission_denied', $this->activeFor($workspace)?->webhook_check_status);
        $this->actingAs($owner)->get(route('workspaces.payments', $workspace))->assertInertia(fn (Assert $page) => $page
            ->where('processors.0.connection.webhook.severity', 'warning')
            ->where('processors.0.connection.webhook.summary', 'SVC cannot read this key\'s webhook endpoints')
            ->where('processors.0.connection.webhook.detail', fn (string $detail): bool => str_contains($detail, 'Webhook Endpoints to Read'))
            ->where('processors.0.connection.webhook.fix', null));
    }

    public function test_check_quietly_logs_a_failure_without_the_key(): void
    {
        [$owner, $workspace] = $this->tenant('quietlog');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $row = $this->activeFor($workspace);
        $this->assertNotNull($row);
        $handler = new TestHandler;
        Log::getLogger()->pushHandler($handler);
        $this->restoreStripeKeys();
        $this->fakeStripeKeys(static function (): array {
            throw new \RuntimeException('boom '.self::KEY_A);
        });

        app(StripeWebhookHealthService::class)->checkQuietly($row);

        $this->assertTrue($handler->hasRecordThatContains('could not be recorded', Level::Warning));
        foreach ($handler->getRecords() as $record) {
            $this->assertStringNotContainsString(self::KEY_A, json_encode($record->toArray(), JSON_THROW_ON_ERROR));
        }
    }

    public function test_check_again_stores_a_fresh_answer(): void
    {
        [$owner, $workspace] = $this->tenant('again');
        $this->saveWithEndpoint($owner, $workspace, self::KEY_A, ['payment_intent.succeeded']);
        $this->assertSame('missing_events', $this->activeFor($workspace)?->webhook_check_status);

        $this->restoreStripeKeys();
        $this->fakeStripeKeys(self::stripeAnswers($this->endpointFor($workspace, StripeWebhookService::REQUIRED_EVENTS)));
        $this->actingAs($owner)->post($this->checkUrl($workspace))->assertNoContent();

        $row = $this->activeFor($workspace);
        $this->assertSame('healthy', $row?->webhook_check_status);
        $this->assertSame([], $row?->webhook_check_missing);
    }

    // ---- what the check finds ----

    public function test_missing_events_are_recorded_and_shown_with_the_endpoint_and_time(): void
    {
        [$owner, $workspace] = $this->tenant('missing');
        $this->saveWithEndpoint($owner, $workspace, self::KEY_A, array_values(array_diff(StripeWebhookService::REQUIRED_EVENTS, ['payment_method.attached', 'customer.updated'])));

        $row = $this->activeFor($workspace);
        $this->assertSame('missing_events', $row?->webhook_check_status);
        $this->assertSame(['payment_method.attached', 'customer.updated'], $row?->webhook_check_missing);
        $this->actingAs($owner)->get(route('workspaces.payments', $workspace))->assertInertia(fn (Assert $page) => $page
            ->where('processors.0.connection.webhook.severity', 'warning')
            ->where('processors.0.connection.webhook.summary', 'Missing: payment_method.attached, customer.updated')
            ->where('processors.0.connection.webhook.missing', ['payment_method.attached', 'customer.updated'])
            ->where('processors.0.connection.webhook.endpoint_url', self::BASE.'/'.$row?->endpoint_token)
            ->where('processors.0.connection.webhook.checked_at', fn (string $at): bool => str_ends_with($at, '+00:00') || str_ends_with($at, 'Z'))
            ->where('processors.0.connection.webhook.fix.action', 'add_events')
            ->where('processors.0.connection.webhook.fix.url', '/workspaces/'.$workspace->public_id.'/payments/stripe/webhook-fix'));
    }

    public function test_a_healthy_endpoint_says_how_many_events(): void
    {
        [$owner, $workspace] = $this->tenant('healthy');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();

        $this->actingAs($owner)->get(route('workspaces.payments', $workspace))->assertInertia(fn (Assert $page) => $page
            ->where('processors.0.connection.webhook.severity', 'ok')
            ->where('processors.0.connection.webhook.summary', 'Webhook: all '.count(StripeWebhookService::REQUIRED_EVENTS).' events subscribed')
            ->where('processors.0.connection.webhook.fix', null));
    }

    public function test_a_disabled_endpoint_a_missing_one_and_the_wrong_mode_are_each_reported(): void
    {
        [$owner, $workspace] = $this->tenant('states');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $token = $this->activeFor($workspace)?->endpoint_token;
        $health = app(StripeWebhookHealthService::class);
        $row = $this->activeFor($workspace);
        $this->assertNotNull($row);

        $cases = [
            'disabled' => $this->endpointList('we_x', self::BASE.'/'.$token, ['*'], 'disabled'),
            'not_found' => $this->endpointList('we_x', 'https://elsewhere.example.test/hook', ['*']),
            'mode_mismatch' => $this->endpointList('we_x', self::BASE.'/'.$token, ['*'], 'enabled', false),
        ];
        foreach ($cases as $expected => $endpoints) {
            $this->restoreStripeKeys();
            $this->fakeStripeKeys(self::stripeAnswers($endpoints));
            $this->assertSame($expected, $health->check($row)->status->value);
            $this->assertSame($expected, $this->activeFor($workspace)?->webhook_check_status);
        }
    }

    public function test_the_wildcard_and_a_url_written_differently_count(): void
    {
        [$owner, $workspace] = $this->tenant('wild');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $row = $this->activeFor($workspace);
        $this->assertNotNull($row);
        $theirs = 'HTTPS://svc.example.test:443/api/webhooks/stripe/'.$row->endpoint_token.'/';
        $this->restoreStripeKeys();
        $this->fakeStripeKeys(self::stripeAnswers($this->endpointList('we_wild', $theirs, ['*'])));

        $this->assertSame(WebhookHealthStatus::Healthy, app(StripeWebhookHealthService::class)->check($row)->status);
    }

    public function test_the_legacy_url_counts_for_the_connection_made_from_the_environment(): void
    {
        [$owner, $workspace] = $this->tenant('legacy');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        DB::table('workspace_payment_connections')->where('workspace_id', $workspace->id)->update(['origin' => 'environment']);
        $row = $this->activeFor($workspace);
        $this->assertNotNull($row);
        $this->restoreStripeKeys();
        $this->fakeStripeKeys(self::stripeAnswers($this->endpointList('we_env', self::BASE, StripeWebhookService::REQUIRED_EVENTS)));

        $this->assertSame(WebhookHealthStatus::Healthy, app(StripeWebhookHealthService::class)->check($row)->status);

        // An entered connection is not served at the legacy URL.
        DB::table('workspace_payment_connections')->where('workspace_id', $workspace->id)->update(['origin' => 'entered']);
        $this->assertSame(WebhookHealthStatus::NotFound, app(StripeWebhookHealthService::class)->check($this->activeFor($workspace) ?? $row)->status);
    }

    public function test_stripe_not_answering_is_recorded_as_unknown_not_as_a_problem_with_the_endpoint(): void
    {
        [$owner, $workspace] = $this->tenant('down');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => 'rk_live_synthetic_webhook_unreachable_q'])->assertNoContent();

        $this->assertSame('unreachable', $this->activeFor($workspace)?->webhook_check_status);
        $this->actingAs($owner)->get(route('workspaces.payments', $workspace))->assertInertia(fn (Assert $page) => $page
            ->where('processors.0.connection.webhook.severity', 'unknown')
            ->where('processors.0.connection.webhook.fix', null));
    }

    public function test_a_connection_never_checked_says_so(): void
    {
        [$owner, $workspace] = $this->tenant('never');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        DB::table('workspace_payment_connections')->update(['webhook_check_status' => null, 'webhook_checked_at' => null, 'webhook_check_missing' => null]);

        $this->actingAs($owner)->get(route('workspaces.payments', $workspace))->assertInertia(fn (Assert $page) => $page
            ->where('processors.0.connection.webhook.summary', 'Webhook not checked yet')
            ->where('processors.0.connection.webhook.severity', 'unknown')
            ->where('processors.0.connection.webhook.checked_at', null)
            ->where('processors.0.connection.webhook.fix', null));
    }

    // ---- who may check and fix, and tenancy ----

    public function test_only_an_owner_or_admin_can_check_or_fix(): void
    {
        [$owner, $workspace] = $this->tenant('roles');
        $member = $this->staff($workspace, 'member');
        $outsider = User::factory()->create();
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $calls = count($this->keyedStripeCalls);

        foreach ([$member, $outsider] as $person) {
            $this->actingAs($person)->post($this->checkUrl($workspace))->assertForbidden();
            $this->actingAs($person)->postJson($this->fixUrl($workspace), ['action' => 'create'])->assertForbidden();
        }
        $this->assertCount($calls, $this->keyedStripeCalls, 'A refused request must not reach Stripe.');

        $admin = $this->staff($workspace, 'admin');
        $this->actingAs($admin)->post($this->checkUrl($workspace))->assertNoContent();
        $this->assertCount($calls + 1, $this->keyedStripeCalls);
    }

    public function test_a_member_sees_the_result_but_not_the_url_or_any_button(): void
    {
        [$owner, $workspace] = $this->tenant('member');
        $member = $this->staff($workspace, 'member');
        $this->saveWithEndpoint($owner, $workspace, self::KEY_A, ['payment_intent.succeeded']);

        $this->actingAs($member)->get(route('workspaces.payments', $workspace))->assertInertia(fn (Assert $page) => $page
            ->where('processors.0.connection.webhook.severity', 'warning')
            ->where('processors.0.connection.webhook.endpoint_url', null)
            ->where('processors.0.connection.webhook.check_url', null)
            ->where('processors.0.connection.webhook.fix', null));
    }

    public function test_one_workspace_can_never_check_or_fix_another_workspaces_connection(): void
    {
        [$ownerA, $workspaceA] = $this->tenant('iso-a');
        [$ownerB, $workspaceB] = $this->tenant('iso-b');
        $this->saveWithEndpoint($ownerA, $workspaceA, self::KEY_A, ['payment_intent.succeeded']);
        $this->saveWithEndpoint($ownerB, $workspaceB, self::KEY_B, ['payment_intent.succeeded']);
        $before = DB::table('workspace_payment_connections')->where('workspace_id', $workspaceB->id)->first();
        $calls = count($this->keyedStripeCalls);

        // A's owner addresses B's workspace: refused before anything is read or sent.
        $this->actingAs($ownerA)->post($this->checkUrl($workspaceB))->assertForbidden();
        $this->actingAs($ownerA)->postJson($this->fixUrl($workspaceB), ['action' => 'add_events'])->assertForbidden();
        $this->assertCount($calls, $this->keyedStripeCalls);
        $this->assertEquals($before, DB::table('workspace_payment_connections')->where('workspace_id', $workspaceB->id)->first());

        // A's own check touches only A's row and uses only A's key.
        $this->actingAs($ownerA)->post($this->checkUrl($workspaceA))->assertNoContent();
        foreach (array_slice($this->keyedStripeCalls, $calls) as $call) {
            $this->assertSame(self::KEY_A, $call['key']);
        }
        $this->assertEquals($before, DB::table('workspace_payment_connections')->where('workspace_id', $workspaceB->id)->first());
    }

    public function test_the_daily_command_records_each_workspace_with_its_own_key_and_a_preview_calls_nothing(): void
    {
        [$ownerA, $workspaceA] = $this->tenant('cmd-a');
        [$ownerB, $workspaceB] = $this->tenant('cmd-b');
        $this->saveWithEndpoint($ownerA, $workspaceA, self::KEY_A, ['payment_intent.succeeded']);
        $this->actingAs($ownerB)->post($this->url($workspaceB), ['secret' => self::KEY_B])->assertNoContent();
        DB::table('workspace_payment_connections')->update(['webhook_check_status' => null, 'webhook_checked_at' => null, 'webhook_check_missing' => null]);
        $calls = count($this->keyedStripeCalls);

        $this->assertSame(0, Artisan::call('svc:payments:check-stripe-webhooks'));
        $output = Artisan::output();
        $this->assertStringContainsString('Preview', $output);
        $this->assertStringContainsString($workspaceA->public_id, $output);
        $this->assertStringContainsString('would_check', $output);
        $this->assertCount($calls, $this->keyedStripeCalls, 'A preview calls nothing.');
        $this->assertNull($this->activeFor($workspaceA)?->webhook_check_status);

        $this->assertSame(0, Artisan::call('svc:payments:check-stripe-webhooks', ['--apply' => true, '--format' => 'json']));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($report['dry_run']);
        $this->assertSame(2, $report['checked']);
        $this->assertNotNull($this->activeFor($workspaceA)?->webhook_checked_at);
        $this->assertNotNull($this->activeFor($workspaceB)?->webhook_checked_at);
        $this->assertStringNotContainsString('rk_live', json_encode($report, JSON_THROW_ON_ERROR));
        $keys = array_column(array_slice($this->keyedStripeCalls, $calls), 'key');
        sort($keys);
        $this->assertSame([self::KEY_A, self::KEY_B], $keys);
    }

    public function test_the_command_can_check_one_workspace_and_validates_its_options(): void
    {
        [$ownerA, $workspaceA] = $this->tenant('one-a');
        [$ownerB, $workspaceB] = $this->tenant('one-b');
        $this->actingAs($ownerA)->post($this->url($workspaceA), ['secret' => self::KEY_A])->assertNoContent();
        $this->actingAs($ownerB)->post($this->url($workspaceB), ['secret' => self::KEY_B])->assertNoContent();
        DB::table('workspace_payment_connections')->update(['webhook_check_status' => null, 'webhook_checked_at' => null]);

        $this->assertSame(0, Artisan::call('svc:payments:check-stripe-webhooks', ['--apply' => true, '--workspace' => $workspaceA->public_id]));
        $this->assertNotNull($this->activeFor($workspaceA)?->webhook_checked_at);
        $this->assertNull($this->activeFor($workspaceB)?->webhook_checked_at);

        $this->assertSame(2, Artisan::call('svc:payments:check-stripe-webhooks', ['--workspace' => 'no-such-workspace']));
        $this->assertSame(2, Artisan::call('svc:payments:check-stripe-webhooks', ['--limit' => '0']));
        $this->assertSame(2, Artisan::call('svc:payments:check-stripe-webhooks', ['--limit' => '1001']));
    }

    public function test_the_daily_command_is_scheduled_with_apply_and_no_queue(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'svc:payments:check-stripe-webhooks'));

        $this->assertNotNull($event);
        $this->assertStringContainsString('--apply', (string) $event->command);
        $this->assertSame('35 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_a_reused_connection_has_no_health_of_its_own(): void
    {
        [$owner, $source] = $this->tenant('src');
        $target = Workspace::query()->create(['name' => 'Reuser', 'slug' => 'reuser-health']);
        $target->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $this->actingAs($owner)->post($this->url($source), ['secret' => self::KEY_A])->assertNoContent();
        $sourceRow = $this->activeFor($source);
        $this->assertNotNull($sourceRow);
        $this->actingAs($owner)->post($this->url($target).'/reuse', ['source' => $sourceRow->public_id])->assertNoContent();
        $calls = count($this->keyedStripeCalls);

        $this->actingAs($owner)->get(route('workspaces.payments', $target))->assertInertia(fn (Assert $page) => $page
            ->where('processors.0.connection.webhook', null));
        $this->actingAs($owner)->post($this->checkUrl($target))->assertNotFound();
        $this->assertCount($calls, $this->keyedStripeCalls);
    }

    // ---- the fix ----

    public function test_adding_events_keeps_everything_already_subscribed_and_touches_no_other_endpoint(): void
    {
        [$owner, $workspace] = $this->tenant('fix');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $row = $this->activeFor($workspace);
        $this->assertNotNull($row);
        $mine = ['payment_intent.succeeded', 'custom.thing.owner.added'];
        $other = $this->endpointList('we_other', 'https://elsewhere.example.test/hook', ['charge.refunded']);
        $this->restoreStripeKeys();
        $this->fakeStripeKeys(self::stripeAnswers($this->endpointList('we_mine', self::BASE.'/'.$row->endpoint_token, $mine) + $other));
        DB::table('workspace_payment_connections')->where('id', $row->id)->update(['webhook_endpoint_id' => 'we_mine']);

        $response = $this->actingAs($owner)->postJson($this->fixUrl($workspace), ['action' => 'add_events']);

        $response->assertOk();
        $this->assertStringContainsString('Added '.(count(StripeWebhookService::REQUIRED_EVENTS) - 1).' missing events', (string) $response->json('message'));
        $updates = $this->callsTo('post', '/v1/webhook_endpoints/we_mine');
        $this->assertCount(1, $updates);
        $sent = $updates[0]['params']['enabled_events'];
        $this->assertSame('payment_intent.succeeded', $sent[0]);
        $this->assertContains('custom.thing.owner.added', $sent);
        $this->assertEqualsCanonicalizing([...$mine, ...array_diff(StripeWebhookService::REQUIRED_EVENTS, $mine)], $sent);
        $this->assertSame([], $this->callsTo('post', '/v1/webhook_endpoints/we_other'), 'Another endpoint is not touched.');
        $this->assertSame([], $this->callsTo('delete', '/v1/webhook_endpoints/we_other'));
        $this->assertSame('healthy', $this->activeFor($workspace)?->webhook_check_status);
    }

    public function test_a_fix_the_page_no_longer_calls_for_is_refused_and_changes_nothing(): void
    {
        [$owner, $workspace] = $this->tenant('stale');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();

        // The endpoint is whole, but the person confirmed "add events" on an old page.
        $this->actingAs($owner)->postJson($this->fixUrl($workspace), ['action' => 'add_events'])
            ->assertUnprocessable()->assertJsonValidationErrors('webhook');
        $this->assertSame([], $this->callsTo('post', '/v1/webhook_endpoints/we_'.$this->suffix(self::KEY_A)));

        // And "create" when one exists.
        $this->actingAs($owner)->postJson($this->fixUrl($workspace), ['action' => 'create'])
            ->assertUnprocessable()->assertJsonValidationErrors('webhook');
        $this->assertCount(1, $this->callsTo('post', '/v1/webhook_endpoints'), 'Only the save created an endpoint.');

        $this->actingAs($owner)->postJson($this->fixUrl($workspace), ['action' => 'delete-everything'])->assertUnprocessable();
        $this->actingAs($owner)->postJson($this->fixUrl($workspace), [])->assertUnprocessable();
    }

    public function test_a_key_without_write_permission_is_told_which_one_to_add(): void
    {
        [$owner, $workspace] = $this->tenant('nowrite');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $row = $this->activeFor($workspace);
        $this->assertNotNull($row);
        // The stored key may read webhook endpoints but not change them; one exists, lacking events.
        $row->forceFill(['secret' => 'rk_live_synthetic_x_no_webhook_write'])->save();
        $this->restoreStripeKeys();
        $this->fakeStripeKeys(self::stripeAnswers($this->endpointList('we_mine', self::BASE.'/'.$row->endpoint_token, ['payment_intent.succeeded'])));
        $this->actingAs($owner)->post($this->checkUrl($workspace))->assertNoContent();
        $this->assertSame('missing_events', $this->activeFor($workspace)?->webhook_check_status);

        $this->actingAs($owner)->postJson($this->fixUrl($workspace), ['action' => 'add_events'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.webhook.0', fn (string $message): bool => str_contains($message, 'Webhook Endpoints to Write'));
    }

    public function test_creating_an_endpoint_stores_the_new_signing_secret_encrypted_and_reports_one_line(): void
    {
        [$owner, $workspace] = $this->tenant('create');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $row = $this->activeFor($workspace);
        $this->assertNotNull($row);
        // The endpoint was deleted in Stripe: the account lists none at SVC's URL.
        $this->restoreStripeKeys();
        $this->fakeStripeKeys(self::stripeAnswers($this->endpointList('we_other', 'https://elsewhere.example.test/hook', ['charge.refunded'])));
        $this->actingAs($owner)->post($this->checkUrl($workspace))->assertNoContent();
        $this->assertSame('not_found', $this->activeFor($workspace)?->webhook_check_status);
        $this->actingAs($owner)->get(route('workspaces.payments', $workspace))->assertInertia(fn (Assert $page) => $page
            ->where('processors.0.connection.webhook.fix.action', 'create')
            ->where('processors.0.connection.webhook.fix.label', 'Create endpoint'));

        $response = $this->actingAs($owner)->postJson($this->fixUrl($workspace), ['action' => 'create']);

        $response->assertOk();
        $this->assertSame('Created the Stripe webhook endpoint and stored its signing secret.', $response->json('message'));
        // The first call was the save's; the second is the fix's.
        $created = array_slice($this->callsTo('post', '/v1/webhook_endpoints'), 1);
        $this->assertCount(1, $created);
        $this->assertSame(self::BASE.'/'.$row->endpoint_token, $created[0]['params']['url']);
        $this->assertEqualsCanonicalizing(StripeWebhookService::EVENTS, $created[0]['params']['enabled_events']);
        $this->assertSame(self::KEY_A, $created[0]['key']);

        $fresh = $this->activeFor($workspace);
        $this->assertNotNull($fresh);
        $newSecret = 'whsec_'.$this->suffix(self::KEY_A);
        $this->assertSame($newSecret, $fresh->webhookSecret());
        $this->assertSame('we_'.$this->suffix(self::KEY_A), $fresh->webhook_endpoint_id);
        // Encrypted at rest exactly like the key: the column never holds the secret itself.
        $raw = (string) DB::table('workspace_payment_connections')->where('id', $fresh->id)->value('webhook_secret');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('whsec_', $raw);
        $this->assertSame($newSecret, decrypt($raw, false));
        $this->assertSame('healthy', $fresh->webhook_check_status);
        // Nothing the browser receives carries it.
        $this->assertStringNotContainsString('whsec_', (string) $response->getContent());
        $this->actingAs($owner)->get(route('workspaces.payments', $workspace))->assertInertia(fn (Assert $page) => $page
            ->where('processors.0.connection.webhook.severity', 'ok'));
        $this->assertStringNotContainsString('whsec_', (string) $this->actingAs($owner)->get(route('workspaces.payments', $workspace))->getContent());
    }

    public function test_creating_without_write_permission_creates_nothing_and_names_the_permission(): void
    {
        [$owner, $workspace] = $this->tenant('createperm');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $this->activeFor($workspace)?->forceFill(['secret' => 'rk_live_synthetic_y_no_webhook_write'])->save();
        $this->restoreStripeKeys();
        $this->fakeStripeKeys(self::stripeAnswers([]));
        $this->actingAs($owner)->post($this->checkUrl($workspace))->assertNoContent();
        $this->assertSame('not_found', $this->activeFor($workspace)?->webhook_check_status);
        $secret = $this->activeFor($workspace)?->webhookSecret();

        $this->actingAs($owner)->postJson($this->fixUrl($workspace), ['action' => 'create'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.webhook.0', fn (string $message): bool => str_contains($message, 'Webhook Endpoints to Write'));
        $this->assertSame($secret, $this->activeFor($workspace)?->webhookSecret(), 'The stored secret is untouched.');
    }

    public function test_a_created_endpoint_is_deleted_again_if_its_secret_cannot_be_stored(): void
    {
        [$owner, $workspace] = $this->tenant('rollback');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $row = $this->activeFor($workspace);
        $this->assertNotNull($row);
        $this->restoreStripeKeys();
        $standard = self::stripeAnswers([]);
        // The connection is removed while Stripe is creating the endpoint, so
        // by the time the new secret could be stored there is no row to hold it.
        $this->fakeStripeKeys(function (string $method, string $path, array $params, string $key) use ($standard, $workspace): array {
            if ($method === 'post' && $path === '/v1/webhook_endpoints') {
                DB::table('workspace_payment_connections')->where('workspace_id', $workspace->id)->update(['revoked_at' => now(), 'active_slot' => null]);
            }

            return $standard($method, $path, $params, $key);
        });
        $created = count($this->callsTo('post', '/v1/webhook_endpoints'));
        $rawBefore = DB::table('workspace_payment_connections')->where('workspace_id', $workspace->id)->value('webhook_secret');
        $row->forceFill(['webhook_check_status' => 'not_found'])->save();

        $this->actingAs($owner)->postJson($this->fixUrl($workspace), ['action' => 'create'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.webhook.0', fn (string $message): bool => str_contains($message, 'removed or replaced'));

        $this->assertCount($created + 1, $this->callsTo('post', '/v1/webhook_endpoints'));
        $this->assertCount(1, $this->callsTo('delete', '/v1/webhook_endpoints/we_'.$this->suffix(self::KEY_A)), 'The endpoint made for nothing is removed.');
        $this->assertSame($rawBefore, DB::table('workspace_payment_connections')->where('workspace_id', $workspace->id)->value('webhook_secret'), 'The old secret is left alone.');
    }

    public function test_nothing_secret_reaches_the_logs_or_the_page(): void
    {
        $handler = new TestHandler;
        Log::getLogger()->pushHandler($handler);
        [$owner, $workspace] = $this->tenant('secrets');
        $this->saveWithEndpoint($owner, $workspace, self::KEY_A, ['payment_intent.succeeded']);
        $this->actingAs($owner)->postJson($this->fixUrl($workspace), ['action' => 'add_events'])->assertOk();

        $html = (string) $this->actingAs($owner)->get(route('workspaces.payments', $workspace))->getContent();
        $this->assertStringNotContainsString(self::KEY_A, $html);
        $this->assertStringNotContainsString('whsec_', $html);
        foreach ($handler->getRecords() as $record) {
            $this->assertStringNotContainsString(self::KEY_A, json_encode($record->toArray(), JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('whsec_', json_encode($record->toArray(), JSON_THROW_ON_ERROR));
        }
    }

    public function test_the_service_refuses_a_non_manager_and_a_workspace_without_a_key(): void
    {
        [$owner, $workspace] = $this->tenant('svc');
        $member = $this->staff($workspace, 'member');
        $service = app(StripeWebhookHealthService::class);

        $this->expectException(AuthorizationException::class);
        try {
            $service->fix($workspace, $member, StripeWebhookHealthService::CREATE);
        } finally {
            try {
                $service->fix($workspace, $owner, StripeWebhookHealthService::CREATE);
                $this->fail('A workspace with no connection has nothing to fix.');
            } catch (\DomainException $expected) {
                $this->assertStringContainsString('no Stripe key', $expected->getMessage());
            }
        }
    }

    public function test_replacing_the_key_checks_the_new_one(): void
    {
        [$owner, $workspace] = $this->tenant('replace');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_B])->assertNoContent();

        $keys = array_column($this->callsTo('get', '/v1/webhook_endpoints'), 'key');
        $this->assertSame([self::KEY_A, self::KEY_B], $keys);
        $this->assertSame('healthy', $this->activeFor($workspace)?->webhook_check_status);
        $this->assertSame(1, WorkspacePaymentConnection::query()->where('workspace_id', $workspace->id)->whereNull('revoked_at')->count());
    }

    public function test_the_credentials_value_object_still_never_prints_its_secret(): void
    {
        $this->assertStringNotContainsString(self::KEY_A, print_r(new PaymentCredentials(self::KEY_A), true));
        $this->assertTrue(app(PaymentConnectionService::class) instanceof PaymentConnectionService);
    }

    // ---- helpers ----

    /**
     * Save the key, then replace the fake with one whose account lists an endpoint
     * at this connection's URL subscribed to `$events`, and check again.
     *
     * @param  list<string>  $events
     */
    private function saveWithEndpoint(User $owner, Workspace $workspace, string $key, array $events): void
    {
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => $key])->assertNoContent();
        $this->restoreStripeKeys();
        $this->fakeStripeKeys(self::stripeAnswers($this->endpointFor($workspace, $events, $key)));
        $this->actingAs($owner)->post($this->checkUrl($workspace))->assertNoContent();
    }

    /**
     * @param  list<string>  $events
     * @return array<string, array<string, mixed>>
     */
    private function endpointFor(Workspace $workspace, array $events, ?string $key = null): array
    {
        $row = $this->activeFor($workspace);
        $this->assertNotNull($row);

        return $this->endpointList($row->webhook_endpoint_id ?? 'we_seeded', self::BASE.'/'.$row->endpoint_token, $events);
    }

    /**
     * @param  list<string>  $events
     * @return array<string, array<string, mixed>>
     */
    private function endpointList(string $id, string $url, array $events, string $status = 'enabled', bool $live = true): array
    {
        return self::seededEndpoint($id, $url, $events, $status, $live);
    }

    /** @return list<array{method: string, path: string, params: array<string, mixed>, key: string}> */
    private function callsTo(string $method, string $path): array
    {
        return array_values(array_filter($this->keyedStripeCalls, static fn (array $call): bool => $call['method'] === $method && $call['path'] === $path));
    }

    private function suffix(string $key): string
    {
        return substr(hash('sha256', $key), 0, 8);
    }

    private function url(Workspace $workspace): string
    {
        return '/workspaces/'.$workspace->public_id.'/payments/stripe';
    }

    private function checkUrl(Workspace $workspace): string
    {
        return $this->url($workspace).'/webhook-check';
    }

    private function fixUrl(Workspace $workspace): string
    {
        return $this->url($workspace).'/webhook-fix';
    }

    private function activeFor(Workspace $workspace): ?WorkspacePaymentConnection
    {
        return WorkspacePaymentConnection::query()->where('workspace_id', $workspace->id)->where('processor', 'stripe')->whereNull('revoked_at')->first();
    }

    private function staff(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /** @return array{0:User,1:Workspace} */
    private function tenant(string $suffix): array
    {
        $owner = User::factory()->create(['email' => 'health-owner-'.$suffix.'@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Health Workspace '.$suffix, 'slug' => 'health-'.$suffix]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$owner, $workspace];
    }
}
