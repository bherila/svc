<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The Square finance commands visit every connected workspace with that
 * workspace's own token, and one workspace failing does not skip the next.
 */
final class SquareFinanceCommandsPerWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private const string TOKEN_FIRST = 'EAAAsynthetic_first_workspace_token_0001';

    private const string TOKEN_SECOND = 'EAAAsynthetic_second_workspace_token_0002';

    private Workspace $first;

    private Workspace $second;

    /** @var list<string> tokens that Square answers with a server error */
    private array $failing = [];

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Cache::flush();
        config([
            'services.square.access_token' => null,
            'services.square.workspace' => null,
            'services.square.version' => '2026-01-22',
            'services.square.max_attempts' => 1,
        ]);
        $this->first = $this->connected('first', self::TOKEN_FIRST, 'MERCH_FIRST');
        $this->second = $this->connected('second', self::TOKEN_SECOND, 'MERCH_SECOND');
        Http::fake(function (Request $request) {
            $token = substr($request->header('Authorization')[0] ?? '', strlen('Bearer '));
            if (in_array($token, $this->failing, true)) {
                return Http::response(['errors' => [['category' => 'API_ERROR', 'code' => 'INTERNAL_SERVER_ERROR']]], 500);
            }
            $merchant = $token === self::TOKEN_FIRST ? 'MERCH_FIRST' : 'MERCH_SECOND';
            if (str_contains($request->url(), '/v2/locations')) {
                return Http::response(['locations' => [['id' => 'LOC_'.$merchant, 'merchant_id' => $merchant]]]);
            }

            return Http::response(['payouts' => []]);
        });
    }

    public function test_backfill_visits_each_connected_workspace_with_its_own_token(): void
    {
        $this->assertSame(0, Artisan::call('svc:finance:backfill-payouts', ['--provider' => 'square', '--apply' => true, '--format' => 'json']));

        $tokens = $this->tokensSent();
        $this->assertContains(self::TOKEN_FIRST, $tokens);
        $this->assertContains(self::TOKEN_SECOND, $tokens);
        $this->assertSame(['MERCH_FIRST', 'MERCH_SECOND'], $this->merchantsPerToken($tokens));
    }

    public function test_backfill_reports_one_workspaces_failure_and_still_runs_the_other(): void
    {
        $this->failing = [self::TOKEN_FIRST];

        $this->assertSame(1, Artisan::call('svc:finance:backfill-payouts', ['--provider' => 'square', '--apply' => true, '--format' => 'json']));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($report['completed']);
        $this->assertStringContainsString($this->first->public_id, (string) $report['providers']['square']['note']);
        $this->assertStringNotContainsString($this->second->public_id, (string) $report['providers']['square']['note']);
        $this->assertContains(self::TOKEN_SECOND, $this->tokensSent(), 'The workspace after the failing one still ran.');
    }

    public function test_backfill_for_one_workspace_never_touches_the_other(): void
    {
        $this->assertSame(0, Artisan::call('svc:finance:backfill-payouts', ['--provider' => 'square', '--workspace' => $this->second->public_id, '--apply' => true, '--format' => 'json']));

        $this->assertSame([self::TOKEN_SECOND], array_values(array_unique($this->tokensSent())));
    }

    public function test_movement_sync_visits_each_connected_workspace_with_its_own_token(): void
    {
        $this->assertSame(0, Artisan::call('svc:finance:sync-processor-movements', ['--provider' => 'square', '--apply' => true, '--format' => 'json']));

        $tokens = $this->tokensSent();
        $this->assertContains(self::TOKEN_FIRST, $tokens);
        $this->assertContains(self::TOKEN_SECOND, $tokens);
        $this->assertSame(['MERCH_FIRST', 'MERCH_SECOND'], $this->merchantsPerToken($tokens));
    }

    public function test_movement_sync_reports_a_failure_and_still_runs_the_other_workspace(): void
    {
        $this->failing = [self::TOKEN_FIRST];

        $this->assertSame(1, Artisan::call('svc:finance:sync-processor-movements', ['--provider' => 'square', '--apply' => true, '--format' => 'json']));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($report['completed']);
        $this->assertSame('sync_incomplete', $report['providers']['square']['note']);
        $this->assertContains(self::TOKEN_SECOND, $this->tokensSent(), 'The workspace after the failing one still ran.');
    }

    public function test_movement_sync_for_one_workspace_never_touches_the_other(): void
    {
        $this->assertSame(0, Artisan::call('svc:finance:sync-processor-movements', ['--provider' => 'square', '--workspace' => $this->first->public_id, '--apply' => true, '--format' => 'json']));

        $this->assertSame([self::TOKEN_FIRST], array_values(array_unique($this->tokensSent())));
    }

    public function test_status_reports_each_workspace_rather_than_one_stand_in(): void
    {
        Artisan::call('svc:square:status', ['--format' => 'json']);
        $status = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([$this->first->public_id, $this->second->public_id], array_column($status['workspaces'], 'workspace'));
        $this->assertTrue($status['configured']);
        $this->assertNull($status['last_run'], 'One run cannot describe two workspaces.');
        $this->assertTrue($status['sync_overdue']);
        $this->assertStringNotContainsString(self::TOKEN_FIRST, json_encode($status, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(self::TOKEN_SECOND, json_encode($status, JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function tokensSent(): array
    {
        return array_values(array_map(
            static fn (array $pair): string => substr($pair[0]->header('Authorization')[0] ?? '', strlen('Bearer ')),
            Http::recorded()->all(),
        ));
    }

    /**
     * The merchant each token's locations answered with, in first-seen order:
     * proof each workspace was read as its own account.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function merchantsPerToken(array $tokens): array
    {
        $seen = [];
        foreach ($tokens as $token) {
            $seen[$token === self::TOKEN_FIRST ? 'MERCH_FIRST' : 'MERCH_SECOND'] = true;
        }

        return array_keys($seen);
    }

    private function connected(string $suffix, string $token, string $merchant): Workspace
    {
        $owner = User::factory()->create(['email' => 'square-fin-'.$suffix.'@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Square Finance '.$suffix, 'slug' => 'square-fin-'.$suffix]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        app(WorkspacePaymentConnections::class)->connect($workspace, PaymentProcessor::Square, ConnectionOrigin::Entered, $owner, [
            'external_account_id' => $merchant,
            'environment' => 'production',
            'secret' => $token,
            'webhook_secret' => 'synthetic-signature-'.$suffix,
        ]);

        return $workspace;
    }
}
