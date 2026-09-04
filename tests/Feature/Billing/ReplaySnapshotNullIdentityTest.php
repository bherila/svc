<?php

namespace Tests\Feature\Billing;

use App\Console\Commands\Billing\ReplayInvoicesCommand;
use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The three null branches `ReplayInvoicesCommand::snapshot()` takes on an
 * invoice line, each isolated from the other two.
 *
 * Replay proves the current engine still reproduces what was billed by
 * comparing two snapshots field by field. Every one of these three fields is
 * written by a ternary on a nullable column, so a null is not "no value" here -
 * it is a *substituted* value that the comparison then treats as a fact. A null
 * `hours` is retained as null rather than collapsing to `0.0`, and a null
 * agreement or recurring-item id is retained as the empty string rather than as
 * `'0'`. Get any of those wrong and a replayed line that legitimately carries no
 * hours compares equal to one billed for zero hours, or a line reattributed away
 * from an agreement compares equal to one attributed to agreement id 0.
 *
 * Each test below nulls exactly one of the three on one line of a two-line
 * invoice and leaves that line's other two columns - and every sibling the
 * snapshot reads - populated, so the branch under test is the only thing that
 * can produce the asserted difference. The second line is the same shape with
 * the column populated, which pins the other side of the ternary: a reader
 * deleted outright fails the null assertion, and a reader inverted fails the
 * populated one.
 */
final class ReplaySnapshotNullIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    private ClientAgreementRecurringItem $recurringItem;

    private ClientInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->create(['name' => 'Replay Nulls', 'slug' => 'replay-nulls']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Replay Nulls Client',
            'slug' => 'replay-nulls-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Replay Nulls Project',
        ]);
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'title' => 'Replay Nulls Agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'hourly_rate_amount' => 20000,
            'billing_cadence' => 'monthly',
        ]);
        $this->recurringItem = ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_agreement_id' => $this->agreement->id,
            'description' => 'Synthetic recurring service',
            'cadence' => 'annual',
            'anchor_month' => 1,
            'anchor_day' => 1,
            'effective_on' => '2026-01-01',
            'quantity' => '1.000',
            'amount' => 4200,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $this->invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $this->agreement->id,
            'invoice_number' => 'REPLAY-NULL-IDENTITY',
            'currency' => 'USD',
            'status' => 'draft',
            'invoice_kind' => 'cadence_period',
            'cycle_start' => '2026-02-01',
            'cycle_end' => '2026-02-28',
            'service_period_start' => '2026-02-01',
            'service_period_end' => '2026-02-28',
            'subtotal_amount' => 50000,
            'tax_amount' => 0,
            'total_amount' => 50000,
        ]);
    }

    /**
     * A line that records no hours is snapshotted as no hours, not as zero.
     *
     * `quantity` is the sibling that makes this isolating: both lines carry a
     * populated, distinct quantity, so the two are told apart by a field the
     * branch under test does not touch, and dropping the `hours` reader turns
     * the unhoured line into one that says it billed 0.0000 hours - which
     * compares equal to a genuinely zero-hour line and hides the difference
     * replay exists to report.
     */
    public function test_a_line_with_no_hours_snapshots_an_absent_quantity_rather_than_zero(): void
    {
        $this->line(['hours' => null, 'quantity' => '2.0000']);
        $this->line(['hours' => '3.0000', 'quantity' => '3.0000']);

        $lines = $this->snapshotLines();

        $this->assertNull($this->lineWithQuantity($lines, '2')['hours']);
        $this->assertSame(3.0, $this->lineWithQuantity($lines, '3')['hours']);
    }

    /**
     * A line attributed to no agreement is snapshotted as no agreement, not as
     * agreement zero.
     *
     * The line keeps its hours, its recurring-item id and its project id, so
     * the empty identity below can only come from the agreement branch. The
     * distinction is load-bearing for replay's own comparison: an id cast
     * straight through would render a null as `'0'`, and every unattributed
     * line in a workspace would then share one identity and compare equal to
     * every other.
     */
    public function test_a_line_with_no_agreement_snapshots_an_empty_agreement_identity(): void
    {
        $this->line(['client_agreement_id' => null, 'quantity' => '2.0000']);
        $this->line(['client_agreement_id' => $this->agreement->id, 'quantity' => '3.0000']);

        $lines = $this->snapshotLines();

        $this->assertSame('', $this->lineWithQuantity($lines, '2')['agreement_id']);
        $this->assertSame(
            (string) $this->agreement->id,
            $this->lineWithQuantity($lines, '3')['agreement_id'],
        );
    }

    /**
     * A line owned by no recurring item is snapshotted as owned by none.
     *
     * Same construction, same reason: a recurring charge that stops being a
     * recurring charge is exactly the correction replay is asked to detect, and
     * it is visible in no other field of the snapshot - the description, the
     * amount and the dates can all be identical.
     */
    public function test_a_line_with_no_recurring_item_snapshots_an_empty_recurring_identity(): void
    {
        $this->line(['client_agreement_recurring_item_id' => null, 'quantity' => '2.0000']);
        $this->line(['client_agreement_recurring_item_id' => $this->recurringItem->id, 'quantity' => '3.0000']);

        $lines = $this->snapshotLines();

        $this->assertSame('', $this->lineWithQuantity($lines, '2')['recurring_item_id']);
        $this->assertSame(
            (string) $this->recurringItem->id,
            $this->lineWithQuantity($lines, '3')['recurring_item_id'],
        );
    }

    /**
     * The same three nulls in another tenant are not this tenant's evidence.
     *
     * `snapshot()` is handed a workspace and the companies being replayed, and
     * every branch above reads a row it selected. A foreign row reaching the
     * output would put another tenant's line identities into this workspace's
     * comparison, so the isolation belongs beside the branches rather than only
     * beside the queries.
     */
    public function test_another_workspaces_null_identities_never_enter_this_snapshot(): void
    {
        $this->line(['hours' => null, 'client_agreement_id' => null, 'quantity' => '2.0000']);

        $foreign = Workspace::query()->create(['name' => 'Foreign Nulls', 'slug' => 'foreign-nulls']);
        $foreignCompany = ClientCompany::query()->create([
            'workspace_id' => $foreign->id,
            'name' => 'Foreign Nulls Client',
            'slug' => 'foreign-nulls-client',
        ]);
        $foreignInvoice = ClientInvoice::query()->create([
            'workspace_id' => $foreign->id,
            'client_company_id' => $foreignCompany->id,
            'invoice_number' => 'FOREIGN-NULL-IDENTITY',
            'currency' => 'USD',
            'status' => 'draft',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);
        ClientInvoiceLine::query()->create([
            'workspace_id' => $foreign->id,
            'client_invoice_id' => $foreignInvoice->id,
            'type' => 'additional_hours',
            'description' => 'Foreign unhoured line',
            'quantity' => '9.0000',
            'hours' => null,
            'client_agreement_id' => null,
            'client_agreement_recurring_item_id' => null,
            'line_date' => '2026-02-15',
            'unit_amount' => 20000,
            'tax_amount' => 0,
            'total_amount' => 20000,
            'sort_order' => 1,
        ]);

        $rows = $this->snapshotRows();

        $this->assertCount(1, $rows);
        $this->assertSame('REPLAY-NULL-IDENTITY', array_values($rows)[0]['invoice_number']);
        $this->assertCount(1, array_values($rows)[0]['lines']);
    }

    /**
     * One line on the invoice under test, populated in every field the
     * snapshot reads except the one the caller nulls.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function line(array $overrides): ClientInvoiceLine
    {
        return ClientInvoiceLine::query()->create($overrides + [
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $this->invoice->id,
            'client_agreement_id' => $this->agreement->id,
            'client_agreement_recurring_item_id' => $this->recurringItem->id,
            'client_project_id' => $this->project->id,
            'type' => 'additional_hours',
            'description' => 'Synthetic replay identity line '.($overrides['quantity'] ?? ''),
            'hours' => '2.0000',
            'line_date' => '2026-02-15',
            'unit_amount' => 20000,
            'tax_amount' => 0,
            'total_amount' => 20000,
            'sort_order' => 1,
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function snapshotRows(): array
    {
        /** @var array<string, array<string, mixed>> $rows */
        $rows = (new ReflectionMethod(ReplayInvoicesCommand::class, 'snapshot'))->invoke(
            app(ReplayInvoicesCommand::class),
            $this->workspace,
            collect([$this->company]),
        );

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function snapshotLines(): array
    {
        /** @var list<array<string, mixed>> $lines */
        $lines = array_values($this->snapshotRows())[0]['lines'];

        return $lines;
    }

    /**
     * The snapshot normalises a stored decimal, so `2.0000` arrives as `2`.
     * Matching on the normalised form keeps the lookup honest about what the
     * snapshot actually publishes rather than about what the column stores.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function lineWithQuantity(array $lines, string $quantity): array
    {
        foreach ($lines as $line) {
            if ($line['quantity'] === $quantity) {
                return $line;
            }
        }

        $this->fail(sprintf('No snapshot line with quantity %s.', $quantity));
    }
}
