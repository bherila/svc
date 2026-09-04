<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The "Sent to the client" table, against rows nobody in this codebase wrote.
 *
 * This screen went out reading `recipients` straight off a `json` column and
 * handing it to the browser, which called `.join` on it. Every row this
 * application writes is a list, so every test passed and the page still came up
 * blank in production: a column is not a type, and a deployment accumulates
 * rows from imports, restores and hand-edits that its own code would never have
 * produced.
 *
 * So these assert against the shapes a delivery row can actually hold, written
 * past the model on purpose. The severity is the reason they are worth the
 * length: the table renders inside the invoice page, so one unreadable row did
 * not degrade a cell - it blanked the whole screen, including the invoice
 * itself and the deliveries either side of it.
 */
class InvoiceDeliveryRecordTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function storedRecipients(): iterable
    {
        // The shape that took the page down.
        yield 'one address stored bare' => ['"ap@synthetic.test"', ['ap@synthetic.test']];
        yield 'an object rather than an array' => [
            '{"0":"ap@synthetic.test","2":"ar@synthetic.test"}',
            ['ap@synthetic.test', 'ar@synthetic.test'],
        ];
        yield 'a well-formed list' => [
            '["ap@synthetic.test","ar@synthetic.test"]',
            ['ap@synthetic.test', 'ar@synthetic.test'],
        ];
        yield 'nothing at all' => ['[]', []];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('storedRecipients')]
    public function test_the_delivery_record_renders_whatever_the_column_holds(string $stored, array $expected): void
    {
        [$owner, $workspace, $company, $invoice] = $this->issuedInvoice();
        $delivery = $this->delivery($workspace, $invoice);

        DB::table('client_invoice_email_deliveries')
            ->where('id', $delivery->id)
            ->update(['recipients' => $stored]);

        $response = $this->actingAs($owner)->get($this->invoiceUrl($workspace, $company, $invoice));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('clients/invoice')
            ->where('deliveries.0.recipients', $expected)
            ->where('deliveries.0.bcc', [])
        );

        // Asserting the values match is not enough. The browser calls `.join`
        // on this, and a PHP array with gaps encodes as a JSON object, which
        // has no `.join` - the encoding is the half that broke.
        $props = $response->viewData('page')['props'];
        $this->assertStringStartsWith('[', (string) json_encode($props['deliveries'][0]['recipients']));
    }

    public function test_an_unreadable_delivery_does_not_take_the_rest_of_the_page_with_it(): void
    {
        [$owner, $workspace, $company, $invoice] = $this->issuedInvoice();
        $this->delivery($workspace, $invoice, ['ap@synthetic.test']);
        $broken = $this->delivery($workspace, $invoice, ['ar@synthetic.test']);
        $this->delivery($workspace, $invoice, ['ops@synthetic.test']);

        DB::table('client_invoice_email_deliveries')
            ->where('id', $broken->id)
            ->update(['recipients' => '"ar@synthetic.test"', 'bcc' => '"cc@synthetic.test"']);

        // Newest first, so the order here is the reverse of the order written.
        $this->actingAs($owner)
            ->get($this->invoiceUrl($workspace, $company, $invoice))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/invoice')
                ->has('deliveries', 3)
                ->where('deliveries.0.recipients', ['ops@synthetic.test'])
                ->where('deliveries.1.recipients', ['ar@synthetic.test'])
                ->where('deliveries.1.bcc', ['cc@synthetic.test'])
                ->where('deliveries.2.recipients', ['ap@synthetic.test'])
                // The invoice itself is on this page. The point of the failure
                // was never one cell.
                ->where('invoice.invoice_number', $invoice->invoice_number)
            );
    }

    public function test_a_blind_copy_that_was_never_recorded_reads_as_none_rather_than_null(): void
    {
        [$owner, $workspace, $company, $invoice] = $this->issuedInvoice();
        $this->delivery($workspace, $invoice);

        // The column is nullable and most rows leave it null: an operator who
        // did not tick "copy me" has no blind copies. `null.length` is as fatal
        // to the render as `string.join` was.
        $this->assertNull(
            DB::table('client_invoice_email_deliveries')->value('bcc'),
            'The fixture should leave bcc null, or this asserts nothing.',
        );

        $this->actingAs($owner)
            ->get($this->invoiceUrl($workspace, $company, $invoice))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('deliveries.0.bcc', []));
    }

    private function invoiceUrl(Workspace $workspace, ClientCompany $company, ClientInvoice $invoice): string
    {
        return "/workspaces/{$workspace->public_id}/clients/{$company->public_id}/invoices/{$invoice->public_id}";
    }

    /** @param list<string> $recipients */
    private function delivery(Workspace $workspace, ClientInvoice $invoice, array $recipients = ['ap@synthetic.test']): ClientInvoiceEmailDelivery
    {
        return ClientInvoiceEmailDelivery::query()->create([
            'workspace_id' => $workspace->id,
            'client_invoice_id' => $invoice->id,
            'recipients' => $recipients,
            'subject' => 'Invoice '.$invoice->invoice_number,
            'status' => 'sent',
        ]);
    }

    /** @return array{0: User, 1: Workspace, 2: ClientCompany, 3: ClientInvoice} */
    private function issuedInvoice(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Synthetic Delivery Workspace',
            'slug' => 'synthetic-delivery-workspace-'.uniqid(),
        ]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Delivery Client',
            'slug' => 'synthetic-delivery-client-'.uniqid(),
            'billing_email' => 'billing@synthetic.test',
        ]);

        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-SYNTH-'.str()->upper(str()->random(8)),
            'currency' => 'USD',
            'issue_date' => '2026-08-15',
            'due_date' => '2026-09-14',
        ], [[
            'type' => 'fee',
            'description' => 'Synthetic monthly retainer',
            'quantity' => 1,
            'unit_amount' => 150000,
            'total_amount' => 150000,
        ]]);
        $service->issue($invoice, $workspace);

        return [$owner, $workspace, $company, $invoice->fresh()];
    }
}
