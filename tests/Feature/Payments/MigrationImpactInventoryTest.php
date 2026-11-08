<?php

namespace Tests\Feature\Payments;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientStripeCustomer;
use App\Models\ClientStripeEvent;
use App\Models\ClientStripePaymentMethod;
use App\Models\CompanySquareCustomer;
use App\Models\InvoiceAutopayAttempt;
use App\Models\InvoiceSquareLink;
use App\Models\SquareLocationSnapshot;
use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\FakesStripeKeys;
use Tests\TestCase;

/**
 * The read-only inventory a preview prints before anyone approves the one-time
 * move of the environment credentials. Nothing here reaches Stripe or Square.
 */
final class MigrationImpactInventoryTest extends TestCase
{
    use FakesStripeKeys;
    use RefreshDatabase;

    private const string ENV_KEY = 'sk_live_synthetic_environment_key_9z9z';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://svc.example.test',
            'services.stripe.secret_key' => self::ENV_KEY,
            'services.stripe.webhook_secret' => 'whsec_synthetic_environment_secret',
            'services.square.access_token' => 'EAAAsynthetic_environment_square',
            'services.square.webhook_signature_key' => 'synthetic-environment-signature',
            'services.square.environment' => 'production',
            'services.square.version' => '2026-01-22',
            'services.square.workspace' => null,
        ]);
        $this->fakeStripeKeys(self::stripeAnswers());
        Http::fake(['*' => Http::response(['locations' => [['id' => 'LOC_ENV', 'merchant_id' => 'MERCH_ENV', 'name' => 'Synthetic']]])]);
        if (! Route::has('svc.billing.stripe.webhook')) {
            require base_path('routes/billing.php');
        }
    }

    protected function tearDown(): void
    {
        $this->restoreStripeKeys();
        parent::tearDown();
    }

    public function test_a_single_workspace_is_the_owner_and_the_report_says_why_in_plain_sentences(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        config(['services.square.workspace' => $alpha->public_id]);
        $this->stripeActivity($alpha, invoices: 2, methods: 3, autopay: 1, held: 1, pending: 2, events: 4);
        $this->squareActivity($alpha, linkedInvoices: 1, customers: 2, receipts: 3);

        $this->artisan('svc:payments:migrate-environment')
            ->expectsOutputToContain('Migration impact inventory - read only')
            ->expectsOutputToContain('Environment credential: sk_live_…9z9z (live mode).')
            ->expectsOutputToContain('Account: not checked. A preview never calls Stripe')
            ->expectsOutputToContain('Account: not checked. A preview never calls Square')
            ->expectsOutputToContain('Owner: Alpha Studio ('.$alpha->public_id.'). SQUARE_WORKSPACE names it (there is no Stripe-specific setting), and no other workspace has Stripe activity.')
            ->expectsOutputToContain('only workspace the environment\'s Square token has ever served')
            ->expectsOutputToContain('Would migrate')
            ->expectsOutputToContain('Alpha Studio')
            ->assertSuccessful();
        $this->assertDatabaseCount('workspace_payment_connections', 0);

        $json = $this->previewJson();
        $stripe = $json['inventory']['processors']['stripe'];
        $this->assertSame('configured', $stripe['ownership']['status']);
        $this->assertFalse($stripe['ownership']['needs_explicit_workspace']);
        $this->assertSame('would_migrate', $stripe['outcome']);
        $this->assertSame('sk_live_…9z9z', $stripe['environment_credential']['hint']);
        $this->assertSame('live', $stripe['environment_credential']['mode']);
        $this->assertFalse($stripe['account']['checked']);
        $this->assertNull($stripe['account']['id']);
        $this->assertCount(1, $stripe['dependent_workspaces']);
        $this->assertSame('will_own', $stripe['dependent_workspaces'][0]['role']);
        $this->assertSame([
            'payable_invoices' => 3, 'autopay_companies' => 1, 'saved_payment_methods' => 3, 'linked_customers' => 0,
            'pending_payments' => 2, 'held_autopay_attempts' => 1, 'recent_webhook_events' => 4,
        ], $stripe['dependent_workspaces'][0]['counts']);
        $square = $json['inventory']['processors']['square'];
        $this->assertSame([
            'payable_invoices' => 1, 'autopay_companies' => 0, 'saved_payment_methods' => 0, 'linked_customers' => 2,
            'pending_payments' => 0, 'held_autopay_attempts' => 0, 'recent_webhook_events' => 3,
        ], $square['dependent_workspaces'][0]['counts']);
        $this->assertSame('production', $square['environment_credential']['mode']);
    }

    public function test_several_workspaces_with_stripe_activity_make_ownership_ambiguous_and_apply_refuses_without_a_choice(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        $beta = $this->workspace('beta', 'Beta Works');
        config(['services.square.workspace' => $alpha->public_id]);
        $this->stripeActivity($alpha, invoices: 1);
        $this->stripeActivity($beta, invoices: 3, autopay: 2);

        $preview = $this->previewJson(expectExit: 2);
        $this->assertTrue($preview['inventory']['needs_explicit_workspace']);
        $stripe = $preview['inventory']['processors']['stripe'];
        $this->assertSame('ambiguous', $stripe['ownership']['status']);
        $this->assertNull($stripe['ownership']['owner']);
        $this->assertSame('ownership_ambiguous', $stripe['outcome']);
        $this->assertSame(['candidate', 'candidate'], array_column($stripe['dependent_workspaces'], 'role'));
        $this->assertSame([$alpha->public_id, $beta->public_id], array_column(array_column($stripe['dependent_workspaces'], 'workspace'), 'public_id'));
        $this->assertSame('configured', $preview['inventory']['processors']['square']['ownership']['status']);

        $this->artisan('svc:payments:migrate-environment')
            ->expectsOutputToContain('Loses access unless chosen')
            ->expectsOutputToContain('Beta Works would stop being served by the environment Stripe credential unless it is chosen as the owner')
            ->assertExitCode(2);

        $this->artisan('svc:payments:migrate-environment', ['--apply' => true])
            ->expectsOutputToContain('Nothing was written. Name the workspace with --workspace=<public id>')
            ->assertExitCode(2);
        $this->assertDatabaseCount('workspace_payment_connections', 0);
        $this->assertSame([], $this->keyedStripeCalls);
        Http::assertNothingSent();

        $this->artisan('svc:payments:migrate-environment', ['--apply' => true, '--workspace' => $beta->public_id])->assertSuccessful();
        $this->assertSame($beta->id, WorkspacePaymentConnection::query()->where('processor', 'stripe')->firstOrFail()->workspace_id);
    }

    public function test_an_explicit_workspace_settles_the_choice_and_the_others_are_named_as_losing_access(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        $beta = $this->workspace('beta', 'Beta Works');
        config(['services.square.workspace' => $alpha->public_id]);
        $this->stripeActivity($alpha, invoices: 1);
        $this->stripeActivity($beta, invoices: 3);
        $this->squareActivity($alpha, linkedInvoices: 2);

        $json = $this->previewJson(['--workspace' => $beta->public_id]);
        $stripe = $json['inventory']['processors']['stripe'];
        $this->assertSame('explicit', $stripe['ownership']['status']);
        $this->assertSame($beta->public_id, $stripe['ownership']['owner']['public_id']);
        $this->assertSame(['loses_access', 'will_own'], array_column($stripe['dependent_workspaces'], 'role'));
        $this->assertFalse($json['inventory']['needs_explicit_workspace']);
        // Square was served only to SQUARE_WORKSPACE: naming Beta takes it away from Alpha.
        $square = $json['inventory']['processors']['square'];
        $this->assertSame(['loses_access', 'will_own'], array_column($square['dependent_workspaces'], 'role'));
        $this->assertSame([$alpha->public_id, $beta->public_id], array_column(array_column($square['dependent_workspaces'], 'workspace'), 'public_id'));
        $this->assertSame(2, $square['dependent_workspaces'][0]['counts']['payable_invoices']);
    }

    public function test_no_workspace_named_anywhere_needs_an_explicit_choice(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        $this->stripeActivity($alpha, invoices: 1);

        $json = $this->previewJson(expectExit: 2);
        $stripe = $json['inventory']['processors']['stripe'];
        $this->assertSame('not_chosen', $stripe['ownership']['status']);
        $this->assertSame('needs_workspace', $stripe['outcome']);
        $this->assertStringContainsString('SQUARE_WORKSPACE is empty', $stripe['ownership']['rule']);
        $this->assertSame([], $json['inventory']['processors']['square']['dependent_workspaces']);

        $this->artisan('svc:payments:migrate-environment', ['--workspace' => 'not-a-uuid'])
            ->expectsOutputToContain('that is not a workspace')
            ->assertExitCode(2);
    }

    public function test_an_existing_connection_is_reported_as_skipped_and_others_as_untouched(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        $beta = $this->workspace('beta', 'Beta Works');
        $gamma = $this->workspace('gamma', 'Gamma Group');
        $this->connect($alpha, 'stripe', ConnectionOrigin::Entered);
        $this->connect($beta, 'square', ConnectionOrigin::Entered);
        $this->connect($gamma, 'stripe', ConnectionOrigin::Entered, revoked: true);

        $json = $this->previewJson(['--workspace' => $alpha->public_id], expectExit: 1);
        $stripe = $json['inventory']['processors']['stripe'];
        $this->assertSame('workspace_already_connected', $stripe['outcome']);
        $this->assertSame('workspace_already_connected', $json['processors']['stripe']['status']);
        $effects = [];
        foreach ($stripe['existing_connections'] as $connection) {
            $effects[$connection['workspace']['public_id']] = [$connection['effect'], $connection['active']];
        }
        $this->assertSame(['blocks_owner', true], $effects[$alpha->public_id]);
        $this->assertSame(['unaffected', false], $effects[$gamma->public_id]);
        $square = $json['inventory']['processors']['square'];
        $this->assertSame('would_migrate', $square['outcome']);
        $this->assertSame([['unaffected', true]], array_map(static fn (array $c): array => [$c['effect'], $c['active']], $square['existing_connections']));

        $this->artisan('svc:payments:migrate-environment', ['--workspace' => $alpha->public_id])
            ->expectsOutputToContain('Alpha Studio already has an active entered connection. The command skips it and never replaces it.')
            ->expectsOutputToContain('Gamma Group has a revoked entered connection. It is untouched.')
            ->assertExitCode(1);
        $this->assertDatabaseCount('workspace_payment_connections', 3);
    }

    public function test_a_workspace_with_its_own_connection_does_not_count_as_depending_on_the_environment(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        $beta = $this->workspace('beta', 'Beta Works');
        config(['services.square.workspace' => $alpha->public_id]);
        $this->stripeActivity($alpha, invoices: 1);
        $this->stripeActivity($beta, invoices: 5);
        $this->connect($beta, 'stripe', ConnectionOrigin::Entered);

        $stripe = $this->previewJson()['inventory']['processors']['stripe'];
        $this->assertSame('configured', $stripe['ownership']['status']);
        $this->assertSame([$alpha->public_id], array_column(array_column($stripe['dependent_workspaces'], 'workspace'), 'public_id'));
    }

    public function test_a_revoked_connection_of_the_owner_does_not_block_the_migration(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        $this->connect($alpha, 'stripe', ConnectionOrigin::Entered, revoked: true);

        $stripe = $this->previewJson(['--workspace' => $alpha->public_id])['inventory']['processors']['stripe'];
        $this->assertSame('would_migrate', $stripe['outcome']);
        $this->assertSame('revoked_earlier', $stripe['existing_connections'][0]['effect']);

        $this->artisan('svc:payments:migrate-environment', ['--workspace' => $alpha->public_id])
            ->expectsOutputToContain('Alpha Studio has a revoked entered connection. It does not block the migration, which adds a new connection.')
            ->assertSuccessful();
    }

    public function test_an_already_migrated_processor_says_so_and_lists_the_environment_connection(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        $this->artisan('svc:payments:migrate-environment', ['--workspace' => $alpha->public_id, '--apply' => true])->assertSuccessful();

        $json = $this->previewJson(['--workspace' => $alpha->public_id]);
        $stripe = $json['inventory']['processors']['stripe'];
        $this->assertSame('already_migrated', $stripe['outcome']);
        $this->assertSame('not_applicable', $stripe['ownership']['status']);
        $this->assertSame('environment_migrated', $stripe['existing_connections'][0]['effect']);
        $this->assertSame([], $stripe['dependent_workspaces']);
    }

    public function test_square_reports_the_location_rule_and_whether_other_locations_exist(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        $beta = $this->workspace('beta', 'Beta Works');
        config(['services.square.workspace' => $alpha->public_id]);
        foreach (['Downtown Studio', 'Airport Kiosk'] as $name) {
            $this->location($alpha, $name);
        }
        $this->location($beta, 'Beta Only');

        $square = $this->previewJson()['inventory']['processors']['square']['square_location'];
        $this->assertSame('not checked', $square['chosen']);
        $this->assertStringContainsString('first location Square returns', $square['rule']);
        $this->assertSame(2, $square['mirrored_locations']);
        $this->assertSame(['Airport Kiosk', 'Downtown Studio'], $square['mirrored_location_names']);
        $this->assertTrue($square['other_locations_exist']);

        $this->artisan('svc:payments:migrate-environment')
            ->expectsOutputToContain('Other locations: yes. This workspace\'s local Square mirror lists 2 (Airport Kiosk, Downtown Studio).')
            ->assertSuccessful();

        SquareLocationSnapshot::query()->where('workspace_id', $alpha->id)->where('name', 'Airport Kiosk')->delete();
        $this->assertFalse($this->previewJson()['inventory']['processors']['square']['square_location']['other_locations_exist']);
        $this->artisan('svc:payments:migrate-environment')->expectsOutputToContain('Other locations: none known.')->assertSuccessful();

        SquareLocationSnapshot::query()->where('workspace_id', $alpha->id)->delete();
        $this->assertNull($this->previewJson()['inventory']['processors']['square']['square_location']['other_locations_exist']);
        $this->artisan('svc:payments:migrate-environment')->expectsOutputToContain('Other locations: unknown.')->assertSuccessful();
    }

    public function test_the_mirror_list_is_bounded_but_the_count_is_not_hidden(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        config(['services.square.workspace' => $alpha->public_id]);
        foreach (range(1, 14) as $n) {
            $this->location($alpha, sprintf('Location %02d', $n));
        }

        $square = $this->previewJson()['inventory']['processors']['square']['square_location'];
        $this->assertCount(10, $square['mirrored_location_names']);
        $this->assertTrue($square['other_locations_exist']);
    }

    public function test_nothing_configured_says_there_is_nothing_to_move(): void
    {
        config([
            'services.stripe.secret_key' => null, 'services.stripe.webhook_secret' => null,
            'services.square.access_token' => null, 'services.square.webhook_signature_key' => null,
        ]);
        $this->workspace('alpha', 'Alpha Studio');

        $json = $this->previewJson();
        foreach (['stripe', 'square'] as $processor) {
            $this->assertFalse($json['inventory']['processors'][$processor]['configured']);
            $this->assertSame('no_environment_credentials', $json['inventory']['processors'][$processor]['outcome']);
            $this->assertSame('not_applicable', $json['inventory']['processors'][$processor]['ownership']['status']);
            $this->assertSame([], $json['inventory']['processors'][$processor]['dependent_workspaces']);
        }
        $this->assertNull($json['inventory']['processors']['square']['square_location']);

        $this->artisan('svc:payments:migrate-environment')
            ->expectsOutputToContain('The environment has no Stripe credentials, so there is nothing to move.')
            ->expectsOutputToContain('The environment has no Square credentials, so there is nothing to move.')
            ->assertSuccessful();
    }

    public function test_counts_never_mix_workspaces(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        $beta = $this->workspace('beta', 'Beta Works');
        $gamma = $this->workspace('gamma', 'Gamma Group');
        $this->stripeActivity($alpha, invoices: 1, methods: 2, autopay: 1, held: 1, pending: 1, events: 1);
        $this->stripeActivity($beta, invoices: 4, methods: 5, autopay: 3, held: 2, pending: 6, events: 7);
        // Gamma has activity of every kind but connected its own account, so it is not a dependent.
        $this->stripeActivity($gamma, invoices: 9, methods: 9, autopay: 9, held: 9, pending: 9, events: 9);
        $this->connect($gamma, 'stripe', ConnectionOrigin::Entered);
        // Rows with no workspace, and rows of another provider, belong to nobody here.
        ClientStripeEvent::query()->create(['workspace_id' => null, 'stripe_event_id' => 'evt_unrouted', 'event_type' => 'x', 'payload_hash' => str_repeat('a', 64)]);
        $this->pendingPayment($alpha, $this->company($alpha), 'square');

        $stripe = $this->previewJson(['--workspace' => $alpha->public_id])['inventory']['processors']['stripe'];
        $counts = [];
        foreach ($stripe['dependent_workspaces'] as $row) {
            $counts[$row['workspace']['public_id']] = array_values($row['counts']);
        }
        $this->assertSame([$alpha->public_id, $beta->public_id], array_keys($counts));
        // payable, autopay, methods, customers, pending, held, events
        $this->assertSame([1, 1, 2, 0, 1, 1, 1], $counts[$alpha->public_id]);
        $this->assertSame([4, 3, 5, 0, 6, 2, 7], $counts[$beta->public_id]);
    }

    public function test_only_open_visible_unpaid_invoices_and_recent_events_are_counted(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        $company = $this->company($alpha);
        $this->invoice($alpha, $company, 'OPEN-1');
        $this->invoice($alpha, $company, 'OPEN-2', status: 'partially_paid');
        $this->invoice($alpha, $company, 'DRAFT-1', status: 'draft');
        $this->invoice($alpha, $company, 'PAID-1', status: 'paid', balance: 0);
        $this->invoice($alpha, $company, 'HIDDEN-1', visible: false);
        $this->invoice($alpha, $company, 'DISPUTED-1', status: 'disputed');
        $this->invoice($alpha, $company, 'ZERO-1', balance: 0);
        $this->event($alpha, 'evt_new', CarbonImmutable::now()->subDays(89));
        $this->event($alpha, 'evt_old', CarbonImmutable::now()->subDays(91));

        $row = $this->previewJson(['--workspace' => $alpha->public_id])['inventory']['processors']['stripe']['dependent_workspaces'][0];
        $this->assertSame(2, $row['counts']['payable_invoices']);
        $this->assertSame(1, $row['counts']['recent_webhook_events']);
    }

    public function test_only_active_square_links_pending_payments_and_customers_count_and_other_workspaces_are_ignored(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        $beta = $this->workspace('beta', 'Beta Works');
        config(['services.square.workspace' => $alpha->public_id]);
        $company = $this->company($alpha);
        $linked = $this->invoice($alpha, $company, 'LINKED');
        $unlinked = $this->invoice($alpha, $company, 'UNLINKED');
        $this->squareLink($linked, 'sqinv_1', unlinked: false);
        $this->squareLink($unlinked, 'sqinv_2', unlinked: true);
        $this->squareCustomer($company, 'sqcus_1', cleared: false);
        $this->squareCustomer($this->company($alpha), 'sqcus_2', cleared: true);
        $this->pendingPayment($alpha, $company, 'square');
        $this->pendingPayment($alpha, $company, 'stripe');
        // Another workspace's Square data is never counted for Alpha.
        $betaCompany = $this->company($beta);
        $this->squareLink($this->invoice($beta, $betaCompany, 'BETA'), 'sqinv_b', unlinked: false);
        $this->squareCustomer($betaCompany, 'sqcus_b', cleared: false);
        $this->pendingPayment($beta, $betaCompany, 'square');

        // Beta's open invoice makes Stripe ambiguous, but Square belongs to the one workspace SQUARE_WORKSPACE names.
        $square = $this->previewJson(expectExit: 2)['inventory']['processors']['square'];
        $this->assertCount(1, $square['dependent_workspaces']);
        $counts = $square['dependent_workspaces'][0]['counts'];
        $this->assertSame(1, $counts['payable_invoices']);
        $this->assertSame(1, $counts['linked_customers']);
        $this->assertSame(1, $counts['pending_payments']);
    }

    public function test_the_preview_writes_nothing_and_calls_no_processor(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        $beta = $this->workspace('beta', 'Beta Works');
        config(['services.square.workspace' => $alpha->public_id]);
        $this->stripeActivity($alpha, invoices: 2, methods: 1, autopay: 1, held: 1, pending: 1, events: 1);
        $this->stripeActivity($beta, invoices: 1);
        $this->squareActivity($alpha, linkedInvoices: 1, customers: 1, receipts: 1);
        $this->location($alpha, 'Downtown Studio');
        $before = $this->snapshot();

        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        foreach ([[], ['--format' => 'json'], ['--workspace' => $beta->public_id], ['--dry-run' => true]] as $options) {
            Artisan::call('svc:payments:migrate-environment', $options);
        }

        $this->assertNotSame([], $statements);
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql, 'A preview ran a statement that is not a SELECT: '.$sql);
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->keyedStripeCalls);
        Http::assertNothingSent();
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_workspaces(): void
    {
        $first = $this->workspace('w0', 'Workspace 0');
        config(['services.square.workspace' => $first->public_id]);
        $this->stripeActivity($first, invoices: 1, methods: 1, autopay: 1, held: 1, pending: 1, events: 1);
        $this->squareActivity($first, linkedInvoices: 1, customers: 1, receipts: 1);
        $this->location($first, 'Downtown Studio');
        $this->stripeActivity($this->workspace('w1', 'Workspace 1'), invoices: 1);

        $small = [$this->queryCount(['--workspace' => $first->public_id]), $this->queryCount([])];

        foreach (range(2, 9) as $n) {
            $workspace = $this->workspace('w'.$n, 'Workspace '.$n);
            $this->stripeActivity($workspace, invoices: 2, methods: 2, autopay: 1, held: 1, pending: 1, events: 2);
            $this->connect($workspace, $n % 2 === 0 ? 'stripe' : 'square', ConnectionOrigin::Entered);
            $this->location($workspace, 'Location '.$n);
        }
        $large = [$this->queryCount(['--workspace' => $first->public_id]), $this->queryCount([])];

        $this->assertSame($small, $large, 'The preview must not run a query per workspace (named owner, then ambiguous).');
        $this->assertLessThanOrEqual(20, max($large));
    }

    public function test_the_json_format_carries_the_plan_and_the_inventory_and_is_pure_json(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        config(['services.square.workspace' => $alpha->public_id]);
        $this->stripeActivity($alpha, invoices: 1);

        $this->assertSame(0, Artisan::call('svc:payments:migrate-environment', ['--format' => 'json']));
        $output = trim(Artisan::output());
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($decoded['dry_run']);
        $this->assertSame($alpha->public_id, $decoded['workspace']);
        $this->assertSame(['status' => 'would_migrate'], $decoded['processors']['stripe']);
        $this->assertTrue($decoded['inventory']['read_only']);
        $this->assertSame(['stripe', 'square'], array_keys($decoded['inventory']['processors']));
        $this->assertSame(
            ['configured', 'environment_credential', 'account', 'ownership', 'outcome', 'dependent_workspaces', 'existing_connections', 'square_location'],
            array_keys($decoded['inventory']['processors']['stripe']),
        );
        $this->assertStringNotContainsString(self::ENV_KEY, $output);
        $this->assertStringNotContainsString('EAAAsynthetic_environment_square', $output);
        $this->assertStringNotContainsString('whsec_synthetic', $output);
        $this->assertStringNotContainsString('synthetic-environment-signature', $output);
    }

    public function test_applying_still_works_for_an_unambiguous_owner_and_prints_no_inventory(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        config(['services.square.workspace' => $alpha->public_id]);
        $this->stripeActivity($alpha, invoices: 1);

        $this->assertSame(0, Artisan::call('svc:payments:migrate-environment', ['--apply' => true, '--format' => 'json']));
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($decoded['dry_run']);
        $this->assertSame(['migrated', 'migrated'], array_column($decoded['processors'], 'status'));
        $this->assertArrayNotHasKey('inventory', $decoded);
        $this->assertSame($alpha->id, WorkspacePaymentConnection::query()->where('processor', 'stripe')->firstOrFail()->workspace_id);
    }

    public function test_the_connection_service_still_agrees_with_the_inventory_about_who_is_connected(): void
    {
        $alpha = $this->workspace('alpha', 'Alpha Studio');
        $beta = $this->workspace('beta', 'Beta Works');
        $this->connect($alpha, 'stripe', ConnectionOrigin::Entered);
        $this->connect($beta, 'stripe', ConnectionOrigin::Entered, revoked: true);

        $connections = app(WorkspacePaymentConnections::class);
        $json = $this->previewJson(['--workspace' => $beta->public_id])['inventory']['processors']['stripe'];

        $this->assertNotNull($connections->activeRow($alpha, PaymentProcessor::Stripe));
        $this->assertNull($connections->activeRow($beta, PaymentProcessor::Stripe));
        $this->assertSame('would_migrate', $json['outcome']);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function previewJson(array $options = [], int $expectExit = 0): array
    {
        $this->assertSame($expectExit, Artisan::call('svc:payments:migrate-environment', [...$options, '--format' => 'json']));

        return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $options */
    private function queryCount(array $options): int
    {
        // The service caches connection reads per request; a fresh application is a fresh request.
        app(WorkspacePaymentConnections::class)->forget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        Artisan::call('svc:payments:migrate-environment', [...$options, '--format' => 'json']);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        // Only this connection's own schema: with no schema, MariaDB lists every
        // database on the server, including the other parallel workers' test
        // databases that are being migrated while this test runs.
        $tables = Schema::getTableListing(Schema::getCurrentSchemaName(), schemaQualified: false);
        $this->assertContains('workspace_payment_connections', $tables);
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $counts['_connections_checksum'] = crc32(json_encode(DB::table('workspace_payment_connections')->get(), JSON_THROW_ON_ERROR));
        $counts['_workspaces_checksum'] = crc32(json_encode(DB::table('workspaces')->get(), JSON_THROW_ON_ERROR));

        return $counts;
    }

    private function workspace(string $slug, string $name): Workspace
    {
        return Workspace::query()->create(['name' => $name, 'slug' => 'inventory-'.$slug]);
    }

    private function company(Workspace $workspace): ClientCompany
    {
        static $n = 0;
        $n++;

        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => 'Northwind Example Co. '.$n, 'slug' => 'inventory-client-'.$n,
        ]);
    }

    private function invoice(Workspace $workspace, ClientCompany $company, string $number, string $status = 'issued', int $balance = 10_000, bool $visible = true): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'INV-'.$workspace->id.'-'.$number,
            'invoice_kind' => 'ad_hoc', 'status' => $status, 'currency' => 'USD', 'subtotal_amount' => 10_000, 'tax_amount' => 0,
            'total_amount' => 10_000, 'paid_amount' => 10_000 - $balance, 'balance_amount' => $balance,
            'issue_date' => '2030-11-01', 'due_date' => '2030-12-01', 'issued_at' => '2030-11-01 15:00:00', 'is_visible_to_client' => $visible,
        ]);
    }

    private function event(Workspace $workspace, string $id, CarbonImmutable $at): void
    {
        $event = ClientStripeEvent::query()->create([
            'workspace_id' => $workspace->id, 'stripe_event_id' => $id, 'event_type' => 'payment_intent.succeeded', 'payload_hash' => str_repeat('b', 64),
        ]);
        DB::table('client_stripe_events')->where('id', $event->id)->update(['created_at' => $at]);
    }

    private function pendingPayment(Workspace $workspace, ClientCompany $company, string $provider): void
    {
        static $n = 0;
        $n++;
        $invoice = $this->invoice($workspace, $company, 'PEND-'.$provider.'-'.$n, status: 'paid', balance: 0);
        ClientInvoicePayment::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'status' => 'pending', 'amount' => 1000,
            'currency' => 'USD', 'method' => 'card', 'provider' => $provider, 'provider_payment_identifier' => $provider.'_pay_'.$n,
        ]);
    }

    private function stripeActivity(Workspace $workspace, int $invoices = 0, int $methods = 0, int $autopay = 0, int $held = 0, int $pending = 0, int $events = 0): void
    {
        static $n = 0;
        $company = $this->company($workspace);
        foreach (range(1, max($invoices, 0)) as $i) {
            if ($invoices > 0) {
                $this->invoice($workspace, $company, 'S'.$i);
            }
        }
        if ($methods > 0) {
            $customer = ClientStripeCustomer::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'stripe_customer_id' => 'cus_synthetic_'.++$n]);
            foreach (range(1, $methods) as $i) {
                ClientStripePaymentMethod::query()->create([
                    'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_stripe_customer_id' => $customer->id,
                    'stripe_payment_method_id' => 'pm_synthetic_'.++$n, 'type' => 'card', 'brand' => 'visa', 'last4' => '4242', 'is_default' => $i === 1,
                ]);
            }
            // A removed card is not "saved".
            $gone = ClientStripePaymentMethod::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_stripe_customer_id' => $customer->id,
                'stripe_payment_method_id' => 'pm_synthetic_'.++$n, 'type' => 'card', 'brand' => 'visa', 'last4' => '0000', 'is_default' => false,
            ]);
            $gone->delete();
        }
        foreach (range(1, max($autopay, 0)) as $i) {
            if ($autopay > 0) {
                $this->company($workspace)->forceFill(['autopay_enabled_at' => '2030-11-01 12:00:00', 'autopay_limit_amount' => 500_000])->save();
            }
        }
        foreach (range(1, max($held, 0)) as $i) {
            if ($held > 0) {
                $invoice = $this->invoice($workspace, $company, 'H'.$i, status: 'paid', balance: 0);
                InvoiceAutopayAttempt::query()->create([
                    'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'client_company_id' => $company->id,
                    'state' => 'claimed', 'claimed_at' => '2030-11-02 12:00:00',
                ]);
                // A settled attempt is not held.
                $settled = $this->invoice($workspace, $company, 'SET'.$i, status: 'paid', balance: 0);
                InvoiceAutopayAttempt::query()->create([
                    'workspace_id' => $workspace->id, 'client_invoice_id' => $settled->id, 'client_company_id' => $company->id,
                    'state' => 'submitted', 'claimed_at' => '2030-11-02 12:00:00',
                ]);
            }
        }
        foreach (range(1, max($pending, 0)) as $i) {
            if ($pending > 0) {
                $this->pendingPayment($workspace, $company, 'stripe');
            }
        }
        foreach (range(1, max($events, 0)) as $i) {
            if ($events > 0) {
                $this->event($workspace, 'evt_synthetic_'.++$n, CarbonImmutable::now()->subDay());
            }
        }
    }

    private function squareActivity(Workspace $workspace, int $linkedInvoices = 0, int $customers = 0, int $receipts = 0): void
    {
        static $n = 0;
        $company = $this->company($workspace);
        foreach (range(1, max($linkedInvoices, 0)) as $i) {
            if ($linkedInvoices > 0) {
                $this->squareLink($this->invoice($workspace, $company, 'SQ'.$i), 'sqinv_synthetic_'.++$n, unlinked: false);
            }
        }
        foreach (range(1, max($customers, 0)) as $i) {
            if ($customers > 0) {
                $this->squareCustomer($this->company($workspace), 'sqcus_synthetic_'.++$n, cleared: false);
            }
        }
        foreach (range(1, max($receipts, 0)) as $i) {
            if ($receipts > 0) {
                DB::table('square_webhook_receipts')->insert([
                    'workspace_id' => $workspace->id, 'event_hash' => hash('sha256', 'receipt'.++$n), 'event_type' => 'payment.updated', 'received_at' => CarbonImmutable::now()->subDay(),
                ]);
            }
        }
    }

    private function squareLink(ClientInvoice $invoice, string $squareInvoiceId, bool $unlinked): void
    {
        $link = new InvoiceSquareLink;
        $link->forceFill([
            'workspace_id' => $invoice->workspace_id, 'client_invoice_id' => $invoice->id, 'square_invoice_id' => $squareInvoiceId,
            'square_invoice_number' => $invoice->invoice_number, 'series_key' => '000001', 'currency' => 'USD', 'match_method' => 'manual', 'score' => 100,
            'reasons' => [], 'linked_at' => '2030-11-02 12:00:00', 'unlinked_at' => $unlinked ? '2030-11-03 12:00:00' : null,
            'active_invoice_slot' => $unlinked ? null : (string) $invoice->id, 'active_square_invoice_slot' => $unlinked ? null : $squareInvoiceId,
        ])->save();
    }

    private function squareCustomer(ClientCompany $company, string $squareCustomerId, bool $cleared): void
    {
        $row = new CompanySquareCustomer;
        $row->forceFill([
            'workspace_id' => $company->workspace_id, 'client_company_id' => $company->id, 'square_customer_id' => $squareCustomerId,
            'confirmed_at' => '2030-11-02 12:00:00', 'cleared_at' => $cleared ? '2030-11-03 12:00:00' : null,
            'active_company_slot' => $cleared ? null : (string) $company->id, 'active_customer_slot' => $cleared ? null : $squareCustomerId,
        ])->save();
    }

    private function location(Workspace $workspace, string $name): void
    {
        static $n = 0;
        SquareLocationSnapshot::query()->create([
            'workspace_id' => $workspace->id, 'square_location_id' => 'LOC_SYNTHETIC_'.++$n, 'name' => $name, 'status' => 'ACTIVE',
            'currency' => 'USD', 'timezone' => 'America/New_York', 'fingerprint' => hash('sha256', $name.$n),
            'synced_at' => '2030-11-02 12:00:00', 'last_seen_at' => '2030-11-02 12:00:00',
        ]);
    }

    private function connect(Workspace $workspace, string $processor, ConnectionOrigin $origin, bool $revoked = false): WorkspacePaymentConnection
    {
        $connection = app(WorkspacePaymentConnections::class)->connect($workspace, PaymentProcessor::from($processor), $origin, null, [
            'external_account_id' => 'acct_synthetic_'.$workspace->id, 'display_name' => 'Synthetic', 'environment' => 'live',
            'secret' => $processor === 'stripe' ? 'sk_live_synthetic_entered_'.$workspace->id : 'EAAAsynthetic_entered_'.$workspace->id,
            'webhook_secret' => 'whsec_synthetic_entered_'.$workspace->id, 'location_id' => $processor === 'square' ? 'LOC_E' : null,
        ]);
        if ($revoked) {
            $connection->forceFill(['revoked_at' => '2030-11-03 12:00:00', 'active_slot' => null])->save();
            app(WorkspacePaymentConnections::class)->forget();
        }

        return $connection;
    }
}
