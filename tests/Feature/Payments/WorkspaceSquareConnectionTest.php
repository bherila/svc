<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquareRequestFailed;
use App\Services\Square\SquareWorkspaceClients;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * Square through an access token each workspace stores for itself. Square is
 * never called: its HTTP is faked and every token is synthetic.
 */
final class WorkspaceSquareConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const string TOKEN_A = 'EAAAsynthetic_token_for_workspace_a_wxyz';

    private const string TOKEN_B = 'EAAAsynthetic_token_for_workspace_b_1234';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://svc.example.test',
            'services.square.access_token' => null,
            'services.square.workspace' => null,
            'services.square.webhook_signature_key' => null,
            'services.square.version' => '2026-01-22',
        ]);
        Http::fake(function (Request $request) {
            $token = $request->header('Authorization')[0] ?? '';
            if (str_contains($token, 'rejected')) {
                return Http::response(['errors' => [['category' => 'AUTHENTICATION_ERROR']]], 401);
            }
            $which = str_contains($token, 'workspace_b') ? 'B' : 'A';

            return Http::response(['locations' => [['id' => 'LOC_'.$which, 'merchant_id' => 'MERCH_'.$which, 'name' => 'Synthetic Location '.$which, 'business_name' => 'Synthetic Business '.$which]]]);
        });
    }

    public function test_an_owner_saves_a_token_after_it_is_validated_and_only_an_owner_or_admin_can(): void
    {
        [$owner, $workspace] = $this->tenant('save');
        $member = $this->staff($workspace, 'member');

        $this->actingAs($member)->post($this->url($workspace), $this->form(self::TOKEN_A, 'LOC_A'))->assertForbidden();
        $this->assertDatabaseCount('workspace_payment_connections', 0);

        $this->actingAs($owner)->post($this->url($workspace), $this->form(self::TOKEN_A, 'LOC_A'))->assertNoContent();
        $row = $this->activeFor($workspace);
        $this->assertSame('MERCH_A', $row->external_account_id);
        $this->assertSame('LOC_A', $row->location_id);
        $this->assertSame('…wxyz', $row->secret_hint);
        $this->assertSame(self::TOKEN_A, $row->secret());
        $this->assertStringNotContainsString('synthetic_token', (string) DB::table('workspace_payment_connections')->where('id', $row->id)->value('secret'));
        $this->assertStringNotContainsString('signature', (string) DB::table('workspace_payment_connections')->where('id', $row->id)->value('webhook_secret'));
        $this->assertArrayNotHasKey('secret', $row->toArray());
        $this->assertStringNotContainsString(self::TOKEN_A, $row->toJson());
    }

    public function test_a_rejected_token_or_a_foreign_location_stores_nothing(): void
    {
        [$owner, $workspace] = $this->tenant('reject');

        $this->actingAs($owner)->post($this->url($workspace), $this->form('EAAArejected_token_xxxxxxxx', 'LOC_A'))->assertSessionHasErrors('secret');
        $this->actingAs($owner)->post($this->url($workspace), $this->form(self::TOKEN_A, 'LOC_ELSEWHERE'))->assertSessionHasErrors('secret');
        $this->actingAs($owner)->post($this->url($workspace), [...$this->form(self::TOKEN_A, 'LOC_A'), 'environment' => 'nonsense'])->assertSessionHasErrors('environment');
        $this->assertDatabaseCount('workspace_payment_connections', 0);
    }

    public function test_square_reads_use_the_workspaces_own_token_and_stop_when_it_is_removed(): void
    {
        [$ownerA, $a] = $this->tenant('a');
        [$ownerB, $b] = $this->tenant('b');
        $this->actingAs($ownerA)->post($this->url($a), $this->form(self::TOKEN_A, 'LOC_A'))->assertNoContent();
        $this->actingAs($ownerB)->post($this->url($b), $this->form(self::TOKEN_B, 'LOC_B'))->assertNoContent();
        $clients = app(SquareWorkspaceClients::class);
        $clients->for($a)->listLocations();
        $clients->for($b)->listLocations();
        $sent = array_map(static fn (array $pair): string => $pair[0]->header('Authorization')[0], Http::recorded()->all());
        $this->assertSame(['Bearer '.self::TOKEN_A, 'Bearer '.self::TOKEN_B], array_slice($sent, -2));

        // A workspace with no connection has no Square, whatever the others hold.
        [, $none] = $this->tenant('none');
        $sentBefore = count(Http::recorded());
        try {
            $clients->for($none)->listLocations();
            $this->fail('A workspace without a connection must not reach Square.');
        } catch (SquareRequestFailed) {
            $this->assertCount($sentBefore, Http::recorded());
        }

        // Removing the connection stops the very next read.
        $this->actingAs($ownerA)->delete($this->url($a))->assertNoContent();
        $this->expectException(SquareRequestFailed::class);
        $clients->for($a)->listLocations();
    }

    public function test_the_environment_account_serves_only_until_the_migration_has_run(): void
    {
        [$owner, $bound] = $this->tenant('bound');
        [, $other] = $this->tenant('other');
        config(['services.square.access_token' => 'EAAAenvironment_token', 'services.square.workspace' => $bound->public_id]);

        $this->assertTrue(SquareConfiguration::for($bound)->isConfigured());
        $this->assertFalse(SquareConfiguration::for($other)->isConfigured());

        $connection = app(WorkspacePaymentConnections::class)->connect($bound, PaymentProcessor::Square, ConnectionOrigin::Environment, $owner, [
            'external_account_id' => 'MERCH_A', 'secret' => 'EAAAenvironment_token',
        ]);
        app(WorkspacePaymentConnections::class)->revoke($connection, $owner);

        // Migrated, then revoked: neither the connection nor the environment serves it.
        $this->assertFalse(SquareConfiguration::for($bound)->isConfigured());
        $this->assertFalse(SquareConfiguration::legacy()->isConfigured());
        $this->assertSame([], SquareConfiguration::servedWorkspaces());
    }

    public function test_webhooks_are_verified_with_the_secret_of_the_workspace_endpoint(): void
    {
        [$ownerA, $a] = $this->tenant('hook-a');
        [$ownerB, $b] = $this->tenant('hook-b');
        $this->actingAs($ownerA)->post($this->url($a), $this->form(self::TOKEN_A, 'LOC_A', 'signature-key-for-a'))->assertNoContent();
        $this->actingAs($ownerB)->post($this->url($b), $this->form(self::TOKEN_B, 'LOC_B', 'signature-key-for-b'))->assertNoContent();
        $tokenA = $this->activeFor($a)->endpoint_token;
        $event = ['event_id' => 'evt_synthetic_1', 'type' => 'invoice.updated', 'merchant_id' => 'MERCH_A', 'data' => ['id' => 'inv_synthetic_1']];

        // B's key does not authorise a delivery to A's endpoint; nothing is written.
        $this->deliver($tokenA, $event, 'signature-key-for-b')->assertForbidden();
        $this->deliver(str_repeat('z', 40), $event, 'signature-key-for-a')->assertNotFound();
        $this->deliver('12', $event, 'signature-key-for-a')->assertNotFound();
        $this->assertSame(0, DB::table('square_webhook_receipts')->count());

        $this->deliver($tokenA, $event, 'signature-key-for-a')->assertOk();
        $this->assertSame(1, DB::table('square_webhook_receipts')->where('workspace_id', $a->id)->count());
        $this->assertSame(0, DB::table('square_webhook_receipts')->where('workspace_id', $b->id)->count());
    }

    public function test_an_event_for_another_merchant_is_logged_and_ignored(): void
    {
        config(['logging.default' => 'capture', 'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug']]);
        [$owner, $a] = $this->tenant('merchant');
        $this->actingAs($owner)->post($this->url($a), $this->form(self::TOKEN_A, 'LOC_A', 'signature-key-for-a'))->assertNoContent();
        $event = ['event_id' => 'evt_synthetic_2', 'type' => 'invoice.updated', 'merchant_id' => 'MERCH_SOMEONE_ELSE', 'data' => ['id' => 'inv_synthetic_2']];

        $this->deliver($this->activeFor($a)->endpoint_token, $event, 'signature-key-for-a')->assertOk()->assertJsonPath('ignored', true);
        $this->assertSame(0, DB::table('square_webhook_receipts')->count());
        /** @var TestHandler $handler */
        $handler = Log::channel('capture')->getLogger()->getHandlers()[0];
        $this->assertTrue($handler->hasWarningThatContains('not for'));
    }

    public function test_a_reused_square_connection_reads_with_the_sources_token_and_belongs_to_the_same_owner(): void
    {
        [$owner, $source] = $this->tenant('rs');
        $target = Workspace::query()->create(['name' => 'Second Square Workspace', 'slug' => 'second-square']);
        $target->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        [$stranger, $foreign] = $this->tenant('foreign');
        $this->actingAs($owner)->post($this->url($source), $this->form(self::TOKEN_A, 'LOC_A'))->assertNoContent();
        $sourceId = $this->activeFor($source)->public_id;

        $this->actingAs($stranger)->post($this->url($foreign).'/reuse', ['source' => $sourceId])->assertSessionHasErrors('source');
        $this->actingAs($owner)->post($this->url($target).'/reuse', ['source' => $sourceId])->assertNoContent();

        app(SquareWorkspaceClients::class)->for($target)->listLocations();
        $this->assertSame('Bearer '.self::TOKEN_A, Http::recorded()->last()[0]->header('Authorization')[0]);

        $this->actingAs($owner)->delete($this->url($source))->assertNoContent();
        $this->assertNull(app(WorkspacePaymentConnections::class)->active($target, PaymentProcessor::Square));
    }

    public function test_the_navigation_links_square_only_for_a_workspace_with_a_connection_and_the_hint_follows_revocation(): void
    {
        [$owner, $workspace] = $this->tenant('nav');
        [$otherOwner, $other] = $this->tenant('nav-other');
        $link = fn (Workspace $w, User $u) => $this->actingAs($u)->get(route('mcp.setup', $w))->viewData('page')['props']['workspaceNavigation']['square_href'] ?? null;

        $this->assertNull($link($workspace, $owner));
        $this->actingAs($owner)->post($this->url($workspace), $this->form(self::TOKEN_A, 'LOC_A'))->assertNoContent();
        $this->assertTrue((bool) Workspace::query()->whereKey($workspace->id)->value('has_square_connection'));
        $this->assertSame(route('square.explore', $workspace, absolute: false), $link($workspace, $owner));
        $this->assertNull($link($other, $otherOwner), 'Another workspace gets no Square link from it.');

        $this->actingAs($owner)->delete($this->url($workspace))->assertNoContent();
        $this->assertFalse((bool) Workspace::query()->whereKey($workspace->id)->value('has_square_connection'));
        $this->assertNull($link($workspace, $owner));
    }

    // ---- helpers ----

    /** @return array<string, string> */
    private function form(string $token, string $location, string $signature = 'synthetic-signature-key'): array
    {
        return ['secret' => $token, 'location_id' => $location, 'environment' => 'production', 'webhook_secret' => $signature];
    }

    private function url(Workspace $workspace): string
    {
        return '/workspaces/'.$workspace->public_id.'/payments/square';
    }

    private function activeFor(Workspace $workspace): ?WorkspacePaymentConnection
    {
        return WorkspacePaymentConnection::query()->where('workspace_id', $workspace->id)->where('processor', 'square')->whereNull('revoked_at')->first();
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
        $owner = User::factory()->create(['email' => 'square-owner-'.$suffix.'@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Square Workspace '.$suffix, 'slug' => 'square-'.$suffix]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$owner, $workspace];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return TestResponse<JsonResponse>
     */
    private function deliver(string $token, array $event, string $key)
    {
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $url = 'https://svc.example.test/api/webhooks/square/'.$token;

        return $this->call('POST', '/api/webhooks/square/'.$token, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => base64_encode(hash_hmac('sha256', $url.$raw, $key, true)),
        ], content: $raw);
    }
}
