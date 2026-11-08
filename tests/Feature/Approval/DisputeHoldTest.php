<?php

namespace Tests\Feature\Approval;

use App\Exceptions\DisputedRowsOnDraft;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A client's dispute holds an hour or an expense out of billing until the
 * provider settles it (docs/ux-overhaul.md 7b″). Today is 2026-10-08; the
 * cadence draft sells October and bills September's work.
 */
final class DisputeHoldTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    private User $consultant;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-08 12:00:00');
        [$this->workspace, $this->company, $this->project, $this->agreement, $this->consultant] = $this->world('hold');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{Workspace, ClientCompany, ClientProject, ClientAgreement, User} */
    private function world(string $name): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic '.$name, 'slug' => 'synthetic-'.$name]);
        $consultant = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $consultant->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => 'Synthetic '.$name.' client', 'slug' => 'synthetic-'.$name.'-client',
            'billing_email' => 'ap-'.$name.'@synthetic.example.test',
        ]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic '.$name.' project', 'is_visible_to_client' => true]);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic retainer', 'status' => 'active',
            'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000,
            'retainer_minutes' => 600, 'retainer_amount' => 100000,
        ]);

        return [$workspace, $company, $project, $agreement, $consultant];
    }

    /** @param array<string, mixed> $overrides */
    private function hours(string $day, int $minutes, array $overrides = [], ?Workspace $workspace = null, ?ClientCompany $company = null, ?ClientProject $project = null, ?User $author = null): ClientTimeEntry
    {
        $workspace ??= $this->workspace;

        return ClientTimeEntry::query()->create($overrides + [
            'workspace_id' => $workspace->id, 'client_company_id' => ($company ?? $this->company)->id, 'client_project_id' => ($project ?? $this->project)->id,
            'user_id' => ($author ?? $this->consultant)->id, 'worked_on' => $day, 'minutes' => $minutes, 'description' => 'Synthetic work',
            'client_visible_description' => 'Synthetic client text', 'is_visible_to_client' => true,
            'status' => 'draft', 'is_billable' => true, 'is_deferred' => false,
        ])->refresh();
    }

    private function generate(?ClientCompany $company = null, ?ClientAgreement $agreement = null): void
    {
        app(ClientInvoicingService::class)->generateCadencePeriod($company ?? $this->company, $agreement ?? $this->agreement, Carbon::parse('2026-10-01'));
    }

    private function draftOf(Workspace $workspace): ClientInvoice
    {
        return ClientInvoice::query()->where('workspace_id', $workspace->id)->where('status', 'draft')->sole();
    }

    private function onAnyLine(ClientTimeEntry $entry): bool
    {
        return DB::table('client_invoice_line_time_entries')->where('client_time_entry_id', $entry->id)->exists();
    }

    private function contact(): User
    {
        $user = User::factory()->create(['email' => $this->company->billing_email]);
        ClientCompanyMembership::query()->create([
            'client_company_id' => $this->company->id, 'user_id' => $user->id, 'role' => 'client',
            'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
        ]);

        return $user;
    }

    private function disputeInPortal(User $contact, string $kind, string $id): void
    {
        $this->actingAs($contact)->from('/portal/x')
            ->post('/portal/'.$this->company->public_id.'/approvals/dispute', ['kind' => $kind, 'id' => $id, 'note' => 'Synthetic question'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    /** @param array<string, mixed> $attributes */
    private function stamp(ClientTimeEntry|ClientExpense $row, array $attributes): void
    {
        DB::table($row->getTable())->where('id', $row->id)->update($attributes);
    }

    // --- release and generation -----------------------------------------

    public function test_a_disputed_consultant_hour_is_not_released_and_the_draft_bills_as_if_it_were_not_there(): void
    {
        $billed = $this->hours('2026-09-10', 700);
        $disputed = $this->hours('2026-09-12', 120);
        $this->stamp($disputed, ['client_disputed_at' => '2026-10-02 09:00:00', 'client_dispute_note' => 'Synthetic question']);

        [$controlWorkspace, $controlCompany, $controlProject, $controlAgreement, $controlConsultant] = $this->world('hold-control');
        $this->hours('2026-09-10', 700, [], $controlWorkspace, $controlCompany, $controlProject, $controlConsultant);

        $this->generate();
        $this->generate($controlCompany, $controlAgreement);

        $this->assertSame('draft', $disputed->fresh()->status, 'Nobody released the disputed hour.');
        $this->assertFalse($this->onAnyLine($disputed));
        $this->assertTrue($this->onAnyLine($billed));
        $this->assertSame($this->draftOf($controlWorkspace)->total_amount, $this->draftOf($this->workspace)->total_amount,
            'Neither the retainer nor the overage counts the held hour.');
    }

    public function test_the_draft_lists_the_held_hour_under_still_deferred_with_a_disputed_tag(): void
    {
        $this->hours('2026-09-10', 60);
        $disputed = $this->hours('2026-09-12', 120, ['status' => 'approved', 'billing_rate_amount' => 10000, 'currency' => 'USD']);
        $this->stamp($disputed, ['client_disputed_at' => '2026-10-02 09:00:00']);

        $this->generate();

        $snapshot = $this->draftOf($this->workspace)->deferredWorkSnapshot();
        $this->assertNotNull($snapshot);
        $this->assertSame([[
            'worked_on' => '2026-09-12', 'hours' => 2.0, 'description' => 'Synthetic client text', 'disputed' => true,
        ]], $snapshot->documentData()['entries']);
        $this->assertFalse($this->onAnyLine($disputed));
    }

    public function test_a_disputed_hour_an_issued_invoice_already_charges_stays_in_the_ledger(): void
    {
        $entry = $this->hours('2026-09-10', 700);
        $this->generate();
        $draft = $this->draftOf($this->workspace);
        app(InvoiceLifecycleService::class)->issue($draft, $this->workspace);
        $this->stamp($entry, ['client_disputed_at' => '2026-10-05 09:00:00']);

        $this->assertTrue(ClientTimeEntry::query()->whereKey($entry->id)->notHeldForDispute()->exists(),
            'An issued charge stands until the correction flow changes it.');
        $this->assertFalse(ClientTimeEntry::query()->whereKey($entry->id)->heldForDispute()->exists());
    }

    // --- a dispute on a draft ------------------------------------------

    public function test_disputing_an_hour_on_a_draft_takes_it_off_the_charge_and_lists_it_as_disputed(): void
    {
        $this->hours('2026-09-10', 600);
        $disputed = $this->hours('2026-09-20', 120);
        $this->generate();
        $this->assertTrue($this->onAnyLine($disputed));

        [$controlWorkspace, $controlCompany, $controlProject, $controlAgreement, $controlConsultant] = $this->world('hold-draft-control');
        $this->hours('2026-09-10', 600, [], $controlWorkspace, $controlCompany, $controlProject, $controlConsultant);
        $this->generate($controlCompany, $controlAgreement);

        $this->disputeInPortal($this->contact(), 'time', $disputed->public_id);

        $draft = $this->draftOf($this->workspace);
        $control = $this->draftOf($controlWorkspace);
        $this->assertFalse($this->onAnyLine($disputed), 'The dispute rebuilt the draft without the hour.');
        $lines = fn (ClientInvoice $invoice): array => $invoice->lines()->orderBy('sort_order')->orderBy('id')->get(['type', 'quantity', 'total_amount', 'description'])->toArray();
        $this->assertSame($lines($control), $lines($draft), 'The draft now charges exactly what it would have without the hour.');
        $this->assertSame($control->total_amount, $draft->total_amount);
        $entries = $draft->deferredWorkSnapshot()?->documentData()['entries'] ?? [];
        $this->assertSame([['worked_on' => '2026-09-20', 'hours' => 2.0, 'description' => 'Synthetic client text', 'disputed' => true]], $entries);
    }

    public function test_a_disputed_expense_leaves_the_cadence_draft(): void
    {
        $this->hours('2026-09-10', 60);
        $expense = ClientExpense::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $this->project->id,
            'created_by_user_id' => $this->consultant->id, 'spent_on' => '2026-09-15', 'amount' => 4200, 'currency' => 'USD',
            'description' => 'Synthetic expense', 'status' => 'approved',
        ]);
        $this->generate();
        $before = $this->draftOf($this->workspace)->total_amount;
        $this->assertNotNull($expense->fresh()->client_invoice_line_id);

        $this->disputeInPortal($this->contact(), 'expense', $expense->public_id);

        $fresh = $expense->fresh();
        $this->assertNull($fresh->client_invoice_line_id);
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($before - 4200, $this->draftOf($this->workspace)->total_amount);
    }

    // --- the door every issue passes through ------------------------------

    public function test_a_draft_still_charging_a_disputed_hour_is_not_issued(): void
    {
        $entry = $this->hours('2026-09-10', 700);
        $this->generate();
        // As if the rebuild had been refused: the dispute stands, the line too.
        $this->stamp($entry, ['client_disputed_at' => '2026-10-05 09:00:00']);

        try {
            app(InvoiceLifecycleService::class)->issue($this->draftOf($this->workspace), $this->workspace);
            $this->fail('A draft charging a disputed hour was issued.');
        } catch (DisputedRowsOnDraft $refused) {
            $this->assertSame('disputed_on_draft', $refused->refusalCode());
            $this->assertSame(1, $refused->hours);
        }
        $this->assertSame('draft', $this->draftOf($this->workspace)->status);
    }

    public function test_another_workspaces_dispute_does_not_stop_this_draft(): void
    {
        $this->hours('2026-09-10', 700);
        [$otherWorkspace, $otherCompany, $otherProject, , $otherConsultant] = $this->world('hold-neighbour');
        $this->hours('2026-09-10', 60, ['client_disputed_at' => '2026-10-05 09:00:00'], $otherWorkspace, $otherCompany, $otherProject, $otherConsultant);
        $this->generate();

        $issued = app(InvoiceLifecycleService::class)->issue($this->draftOf($this->workspace), $this->workspace);

        $this->assertSame('issued', $issued->status);
    }
}
