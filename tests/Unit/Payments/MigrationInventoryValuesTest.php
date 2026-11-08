<?php

namespace Tests\Unit\Payments;

use App\Support\Payments\Inventory\ExistingConnection;
use App\Support\Payments\Inventory\ImpactCounts;
use App\Support\Payments\Inventory\ImpactRole;
use App\Support\Payments\Inventory\MigrationInventory;
use App\Support\Payments\Inventory\MigrationOutcome;
use App\Support\Payments\Inventory\OwnershipStatus;
use App\Support\Payments\Inventory\ProcessorInventory;
use App\Support\Payments\Inventory\SquareLocationFacts;
use App\Support\Payments\Inventory\WorkspaceImpact;
use App\Support\Payments\Inventory\WorkspaceRef;
use App\Support\Payments\PaymentProcessor;
use PHPUnit\Framework\TestCase;

/** The values the migration inventory is made of: no database, no framework. */
final class MigrationInventoryValuesTest extends TestCase
{
    public function test_only_an_undecided_owner_needs_an_explicit_workspace(): void
    {
        $needs = [];
        foreach (OwnershipStatus::cases() as $status) {
            $needs[$status->value] = $status->needsExplicitChoice();
        }

        $this->assertSame([
            'explicit' => false, 'configured' => false, 'ambiguous' => true, 'not_chosen' => true, 'not_applicable' => false,
        ], $needs);
    }

    public function test_outcomes_say_which_ones_fail_a_run_and_each_has_its_own_sentence(): void
    {
        $fails = [];
        $sentences = [];
        foreach (MigrationOutcome::cases() as $outcome) {
            $fails[$outcome->value] = $outcome->failsRun();
            $sentences[] = $outcome->sentence();
        }

        $this->assertSame([
            'no_environment_credentials' => false, 'already_migrated' => false, 'workspace_already_connected' => true,
            'ownership_ambiguous' => true, 'needs_workspace' => true, 'would_migrate' => false,
        ], $fails);
        $this->assertCount(count(MigrationOutcome::cases()), array_unique($sentences));
        $this->assertStringContainsString('--workspace=<public id>', MigrationOutcome::OwnershipAmbiguous->sentence());
        $this->assertStringContainsString('--workspace=<public id>', MigrationOutcome::NeedsWorkspace->sentence());
        $this->assertStringContainsString('never replaces', MigrationOutcome::WorkspaceAlreadyConnected->sentence());
    }

    public function test_roles_have_distinct_labels(): void
    {
        $this->assertSame('Will own the connection', ImpactRole::WillOwn->label());
        $this->assertSame('Loses access', ImpactRole::LosesAccess->label());
        $this->assertSame('Loses access unless chosen', ImpactRole::Candidate->label());
    }

    public function test_counts_are_empty_only_when_every_count_is_zero(): void
    {
        $this->assertTrue((new ImpactCounts)->isEmpty());
        foreach (['payableInvoices', 'autopayCompanies', 'savedPaymentMethods', 'linkedCustomers', 'pendingPayments', 'heldAutopayAttempts', 'recentWebhookEvents'] as $field) {
            $this->assertFalse((new ImpactCounts(...[$field => 1]))->isEmpty(), $field.' alone makes a workspace a dependent.');
        }
    }

    public function test_counts_serialise_every_measure_under_its_own_name(): void
    {
        $this->assertSame([
            'payable_invoices' => 1, 'autopay_companies' => 2, 'saved_payment_methods' => 3, 'linked_customers' => 4,
            'pending_payments' => 5, 'held_autopay_attempts' => 6, 'recent_webhook_events' => 7,
        ], (new ImpactCounts(1, 2, 3, 4, 5, 6, 7))->toArray());
    }

    public function test_a_workspace_reference_is_labelled_with_its_name_and_public_id_only(): void
    {
        $ref = new WorkspaceRef('00000000-0000-4000-8000-000000000001', 'Example Studio');

        $this->assertSame('Example Studio (00000000-0000-4000-8000-000000000001)', $ref->label());
        $this->assertSame(['public_id' => '00000000-0000-4000-8000-000000000001', 'name' => 'Example Studio'], $ref->toArray());
    }

    public function test_other_locations_are_known_only_from_a_mirror_that_has_synced(): void
    {
        $this->assertNull((new SquareLocationFacts(0, []))->otherLocationsExist());
        $this->assertFalse((new SquareLocationFacts(1, ['Main Street']))->otherLocationsExist());
        $this->assertTrue((new SquareLocationFacts(2, ['Main Street', 'Pop-up']))->otherLocationsExist());

        $facts = (new SquareLocationFacts(2, ['Main Street', 'Pop-up']))->toArray();
        $this->assertSame('not checked', $facts['chosen']);
        $this->assertSame(SquareLocationFacts::RULE, $facts['rule']);
        $this->assertSame(2, $facts['mirrored_locations']);
        $this->assertSame(['Main Street', 'Pop-up'], $facts['mirrored_location_names']);
        $this->assertTrue($facts['other_locations_exist']);
    }

    public function test_an_existing_connection_is_explained_in_a_sentence_per_effect(): void
    {
        $ws = new WorkspaceRef('00000000-0000-4000-8000-000000000001', 'Example Studio');

        $this->assertSame(
            'Example Studio already has an active entered connection. The command skips it and never replaces it.',
            (new ExistingConnection($ws, 'entered', true, ExistingConnection::BLOCKS_OWNER))->sentence(),
        );
        $this->assertSame(
            'Example Studio has a revoked entered connection. It does not block the migration, which adds a new connection.',
            (new ExistingConnection($ws, 'entered', false, ExistingConnection::REVOKED_EARLIER))->sentence(),
        );
        $this->assertSame(
            'Example Studio holds an active environment connection made from the environment credentials, so the environment is already retired for this processor.',
            (new ExistingConnection($ws, 'environment', true, ExistingConnection::ENVIRONMENT_MIGRATED))->sentence(),
        );
        $untouched = new ExistingConnection($ws, 'reused', true, ExistingConnection::UNAFFECTED);
        $this->assertSame('Example Studio has an active reused connection. It is untouched.', $untouched->sentence());
        $this->assertSame(
            ['workspace' => $ws->toArray(), 'origin' => 'reused', 'active' => true, 'effect' => 'unaffected'],
            $untouched->toArray(),
        );
    }

    public function test_an_inventory_asks_for_a_workspace_when_any_processor_cannot_decide(): void
    {
        $ws = new WorkspaceRef('00000000-0000-4000-8000-000000000001', 'Example Studio');
        $decided = $this->processor(PaymentProcessor::Square, OwnershipStatus::Configured, $ws);
        $undecided = $this->processor(PaymentProcessor::Stripe, OwnershipStatus::Ambiguous, null);

        $settled = new MigrationInventory(null, ['stripe' => $this->processor(PaymentProcessor::Stripe, OwnershipStatus::Explicit, $ws), 'square' => $decided]);
        $blocked = new MigrationInventory(null, ['stripe' => $undecided, 'square' => $decided]);

        $this->assertFalse($settled->needsExplicitWorkspace());
        $this->assertTrue($blocked->needsExplicitWorkspace());
        $this->assertSame($undecided, $blocked->for(PaymentProcessor::Stripe));
        $this->assertTrue($blocked->toArray()['needs_explicit_workspace']);
        $this->assertTrue($blocked->toArray()['read_only']);
        $this->assertNull($blocked->toArray()['named_workspace']);
        $this->assertSame($ws->toArray(), (new MigrationInventory($ws, []))->toArray()['named_workspace']);
        $this->assertSame(
            ['stripe' => $undecided->toArray(), 'square' => $decided->toArray()],
            $blocked->toArray()['processors'],
            'Each processor is serialised to plain data, not left as an object.',
        );
    }

    public function test_a_processor_inventory_never_claims_an_account_it_did_not_check(): void
    {
        $ws = new WorkspaceRef('00000000-0000-4000-8000-000000000001', 'Example Studio');
        $impact = new WorkspaceImpact($ws, ImpactRole::WillOwn, new ImpactCounts(payableInvoices: 2));
        $existing = new ExistingConnection($ws, 'entered', false, ExistingConnection::UNAFFECTED);
        $inventory = new ProcessorInventory(
            PaymentProcessor::Stripe, true, 'sk_live_…9z9z', 'live', OwnershipStatus::Explicit, $ws, 'You named it with --workspace.',
            MigrationOutcome::WouldMigrate, [$impact], [$existing], null,
        );
        $this->assertSame([
            'workspace' => $ws->toArray(), 'role' => 'will_own', 'counts' => (new ImpactCounts(payableInvoices: 2))->toArray(),
        ], $impact->toArray());

        $array = $inventory->toArray();

        $this->assertSame(['id' => null, 'checked' => false, 'note' => 'A preview never calls Stripe; --apply reads the account and stores its id.'], $array['account']);
        $this->assertSame(['hint' => 'sk_live_…9z9z', 'mode' => 'live'], $array['environment_credential']);
        $this->assertSame([
            'status' => 'explicit', 'owner' => $ws->toArray(), 'rule' => 'You named it with --workspace.', 'needs_explicit_workspace' => false,
        ], $array['ownership']);
        $this->assertSame([$impact->toArray()], $array['dependent_workspaces']);
        $this->assertSame('will_own', $array['dependent_workspaces'][0]['role']);
        $this->assertSame(2, $array['dependent_workspaces'][0]['counts']['payable_invoices']);
        $this->assertSame([$existing->toArray()], $array['existing_connections']);
        $this->assertNull($array['square_location']);
        $this->assertSame('would_migrate', $array['outcome']);
        $this->assertTrue($array['configured']);
    }

    private function processor(PaymentProcessor $processor, OwnershipStatus $status, ?WorkspaceRef $owner): ProcessorInventory
    {
        return new ProcessorInventory($processor, true, 'hint', 'live', $status, $owner, 'rule', MigrationOutcome::WouldMigrate, [], [], null);
    }
}
