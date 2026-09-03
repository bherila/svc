<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceDocumentService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoiceLineDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\TestCase;

/**
 * What an invoice line was billed from.
 *
 * The pivot has carried this since the billing workflow was written and nothing
 * ever showed it, so a client reading "Deferred work items applied to retainer
 * (12.50 hrs)" had no way to ask which work.
 *
 * The reason most of this file is about the client's copy: the same PDF route
 * serves operators and portal users, and an appendix built for an operator and
 * handed to a client publishes every internal note behind a bill.
 */
class InvoiceLineDetailTest extends TestCase
{
    use AssertsSurfaceIsolation, RefreshDatabase;

    private const INTERNAL = 'Chased their finance team again about the missing PO';

    private const CLIENT_SAFE = 'Reconciled the purchase order';

    protected function setUp(): void
    {
        parent::setUp();

        if (! Route::has('svc.billing.invoices.pdf')) {
            require base_path('routes/billing.php');
        }
    }

    public function test_an_operator_sees_every_entry_and_its_internal_description(): void
    {
        [$workspace, $invoice] = $this->invoiceWithWork();

        $detail = InvoiceLineDetail::forInvoice($invoice, InvoiceLineDetail::OPERATOR);
        $items = $detail[$invoice->lines()->sole()->public_id] ?? [];

        $this->assertCount(2, $items);
        $this->assertSame(self::INTERNAL, $items[0]['description']);
        $this->assertSame(90, $items[0]['minutes']);
        $this->assertSame('Synthetic Detail Project', $items[0]['project']);
        $this->assertSame('2026-08-10', $items[0]['worked_on']);
        unset($workspace);
    }

    /**
     * Every line with work behind it, not just the first.
     *
     * An invoice usually carries several - a retainer draw and the hours over
     * it, or one line per project - and a builder that returned only the first
     * would look entirely correct on any invoice that has one.
     */
    public function test_each_line_carries_its_own_work(): void
    {
        [$workspace, $invoice] = $this->invoiceWithWork();
        $project = ClientProject::query()->where('workspace_id', $workspace->id)->sole();

        $second = $invoice->lines()->create([
            'workspace_id' => $invoice->workspace_id,
            'type' => 'additional_hours',
            'description' => 'A second billed line',
            'quantity' => '0.5',
            'unit_amount' => 22500,
            'total_amount' => 11250,
            'sort_order' => 5,
        ]);
        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $project->client_company_id,
            'client_project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-08-20',
            'minutes' => 30,
            'description' => 'Work behind the second line',
            'status' => 'approved',
        ]);
        $second->timeEntries()->attach($entry->id, ['workspace_id' => $workspace->id]);

        $detail = InvoiceLineDetail::forInvoice($invoice->fresh(), InvoiceLineDetail::OPERATOR);

        $this->assertCount(2, $detail);
        $this->assertSame(
            'Work behind the second line',
            $detail[$second->public_id][0]['description'],
        );
        $this->assertSame(30, $detail[$second->public_id][0]['minutes']);
    }

    public function test_a_client_sees_only_what_was_written_for_them_to_read(): void
    {
        [, $invoice] = $this->invoiceWithWork();

        $detail = InvoiceLineDetail::forInvoice($invoice, InvoiceLineDetail::CLIENT);
        $items = $detail[$invoice->lines()->sole()->public_id] ?? [];

        // One of the two entries carries a client-safe description. The other is
        // withheld entirely rather than blanked: a row saying work happened that
        // the client is not being told about reads worse than saying nothing.
        $this->assertCount(1, $items);
        $this->assertSame(self::CLIENT_SAFE, $items[0]['description']);

        $encoded = json_encode($detail);
        $this->assertStringNotContainsString(self::INTERNAL, (string) $encoded);
    }

    public function test_the_client_pdf_carries_no_internal_description(): void
    {
        [$workspace, $invoice, $client] = $this->invoiceWithWork(withPortalUser: true);

        $pdf = $this->actingAs($client)
            ->get("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/pdf")
            ->assertOk()
            ->getContent();

        // Dompdf compresses its streams, so the text is not greppable in the
        // output. The rendered HTML is the same document one step earlier, and
        // it is what the appendix is built from.
        $html = app(InvoiceDocumentService::class)
            ->html($invoice, InvoiceLineDetail::CLIENT)
            ->render();

        $this->assertStringContainsString(self::CLIENT_SAFE, $html);
        $this->assertStringNotContainsString(self::INTERNAL, $html);
        $this->assertNotSame('', $pdf);
    }

    public function test_a_line_with_no_work_behind_it_is_absent_rather_than_empty(): void
    {
        [, $invoice] = $this->invoiceWithWork();

        // A second line billed from nothing - a retainer sold for the coming
        // cycle is a charge, not a record of hours. It must not appear with an
        // empty list, because the screen offers a disclosure control for every
        // key it is handed.
        $invoice->lines()->create([
            'workspace_id' => $invoice->workspace_id,
            'type' => 'retainer',
            'description' => 'Monthly retainer',
            'quantity' => '1',
            'unit_amount' => 450000,
            'total_amount' => 450000,
            'sort_order' => 9,
        ]);

        $detail = InvoiceLineDetail::forInvoice($invoice->fresh(), InvoiceLineDetail::OPERATOR);

        $this->assertCount(1, $detail);
    }

    /**
     * Visibility is the operator's decision, and it is not the description.
     *
     * An entry can carry a client-safe description and still be withheld - the
     * text was written, the decision to show it was not taken, or was taken
     * back. The query asks for both, and this is the case that tells the two
     * conditions apart: with only the description checked, an entry the
     * operator has hidden appears on the client's copy of their own invoice.
     */
    public function test_an_entry_hidden_from_the_client_is_withheld_even_with_a_safe_description(): void
    {
        [$workspace, $invoice] = $this->invoiceWithWork();
        $project = ClientProject::query()->where('workspace_id', $workspace->id)->sole();

        $hidden = ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $project->client_company_id,
            'client_project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-08-12',
            'minutes' => 45,
            'description' => 'Internal note on the hidden entry',
            'client_visible_description' => 'Written but not shown',
            'is_visible_to_client' => false,
            'status' => 'approved',
        ]);
        $invoice->lines()->sole()->timeEntries()
            ->attach($hidden->id, ['workspace_id' => $workspace->id]);

        $items = InvoiceLineDetail::forInvoice($invoice->fresh(), InvoiceLineDetail::CLIENT)[
            $invoice->lines()->sole()->public_id
        ];

        $this->assertStringNotContainsString('Written but not shown', (string) json_encode($items));
        $this->assertSame([self::CLIENT_SAFE], array_column($items, 'description'));
    }

    /**
     * The work is read in a fixed number of queries, whatever the invoice holds.
     *
     * Every line and every entry behind it is loaded up front, which is the
     * whole reason this is built once for the invoice rather than read per
     * line: an invoice with forty lines is ordinary, and a relation touched
     * lazily inside the loop is the N+1 that makes a PDF time out. Asserted as
     * a shape rather than a number, so a legitimately added query does not
     * break it and a query that starts repeating per row does.
     */
    public function test_reading_the_work_costs_the_same_however_many_lines_there_are(): void
    {
        [$workspace, $invoice] = $this->invoiceWithWork();
        $project = ClientProject::query()->where('workspace_id', $workspace->id)->sole();

        $read = fn () => InvoiceLineDetail::forInvoice(
            ClientInvoice::query()->findOrFail($invoice->id),
            InvoiceLineDetail::OPERATOR,
        );

        $few = $this->queriesDuring($read);

        foreach (range(1, 4) as $index) {
            $line = $invoice->lines()->create([
                'workspace_id' => $invoice->workspace_id,
                'type' => 'additional_hours',
                'description' => "Extra billed line {$index}",
                'quantity' => '1',
                'unit_amount' => 22500,
                'total_amount' => 22500,
                'sort_order' => 10 + $index,
            ]);
            $entry = ClientTimeEntry::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $project->client_company_id,
                'client_project_id' => $project->id,
                'user_id' => User::factory()->create()->id,
                'worked_on' => '2026-08-1'.$index,
                'minutes' => 60,
                'description' => "Work behind extra line {$index}",
                'status' => 'approved',
            ]);
            $line->timeEntries()->attach($entry->id, ['workspace_id' => $workspace->id]);
        }

        $this->assertGreaterThan(0, $few);
        $this->assertSame(
            $few,
            $this->queriesDuring($read),
            'Reading the work behind an invoice grew with its lines, which is an N+1.',
        );
    }

    /** @return array{0: Workspace, 1: ClientInvoice, 2: User} */
    private function invoiceWithWork(bool $withPortalUser = false): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Synthetic Detail Workspace',
            'slug' => 'synthetic-detail-workspace-'.uniqid(),
        ]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Detail Client',
            'slug' => 'synthetic-detail-client-'.uniqid(),
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Synthetic Detail Project',
            'is_visible_to_client' => true,
        ]);

        $client = User::factory()->create();

        if ($withPortalUser) {
            $company->portalUsers()->attach($client, ['role' => 'client']);
        }

        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-SYNTH-'.str()->upper(str()->random(8)),
            'currency' => 'USD',
            'issue_date' => '2026-08-15',
            'due_date' => '2026-09-14',
        ], [[
            'type' => 'additional_hours',
            'description' => 'Additional hours',
            'quantity' => '2.5',
            'unit_amount' => 22500,
            'total_amount' => 56250,
        ]]);
        $service->issue($invoice, $workspace);
        $invoice->forceFill(['is_visible_to_client' => true])->save();

        $line = $invoice->lines()->sole();

        foreach ([
            [self::INTERNAL, null, 90],
            ['Wrote up the reconciliation', self::CLIENT_SAFE, 60],
        ] as [$description, $clientDescription, $minutes]) {
            $entry = ClientTimeEntry::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'client_project_id' => $project->id,
                'user_id' => $owner->id,
                'worked_on' => '2026-08-10',
                'minutes' => $minutes,
                'description' => $description,
                'client_visible_description' => $clientDescription,
                'is_visible_to_client' => $clientDescription !== null,
                'status' => 'approved',
            ]);

            $line->timeEntries()->attach($entry->id, ['workspace_id' => $workspace->id]);
        }

        return [$workspace, $invoice->fresh(), $client];
    }
}
