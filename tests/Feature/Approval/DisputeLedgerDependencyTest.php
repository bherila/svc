<?php

namespace Tests\Feature\Approval;

use App\Exceptions\DisputedWorkChanged;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Approval\DisputedDraftRebuilder;
use App\Services\Approval\DisputeSettlements;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Approval\DisputeSettlement;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Disputed historical work the later drafts do not link (docs/ux-overhaul.md
 * 7b″): its dispute, answer or write-off moves it in or out of the ledger, and
 * the capacity a later draft's charges were sized against moves with it.
 *
 * A monthly agreement throughout: the invoice generated on the 1st reconciles
 * the month before. Synthetic rows only.
 */
final class DisputeLedgerDependencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-03-10 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{Workspace, ClientCompany, ClientProject, ClientAgreement, User} */
    private function world(string $name, int $retainerMinutes): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic '.$name, 'slug' => 'synthetic-ledger-'.$name]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic '.$name.' client', 'slug' => 'synthetic-ledger-'.$name.'-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic '.$name.' project']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic agreement', 'status' => 'active',
            'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000,
            'retainer_minutes' => $retainerMinutes, 'retainer_amount' => $retainerMinutes * 100,
            'rollover_months' => 0, 'catch_up_threshold_minutes' => 0,
        ]);

        return [$workspace, $company, $project, $agreement, $owner];
    }

    private function hours(Workspace $workspace, ClientCompany $company, ClientProject $project, User $author, string $day, int $minutes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => $author->id, 'worked_on' => $day, 'minutes' => $minutes, 'description' => 'Synthetic work',
            'client_visible_description' => 'Synthetic client text', 'is_visible_to_client' => true,
            'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD',
        ])->refresh();
    }

    /** The dispute's stamp; the rebuild after it is driven explicitly where it matters. */
    private function stampDispute(ClientTimeEntry $entry): void
    {
        ClientTimeEntry::query()->where('workspace_id', $entry->workspace_id)->whereKey($entry->id)->update([
            'client_disputed_at' => now(), 'client_dispute_note' => 'Synthetic question', 'lock_version' => DB::raw('lock_version + 1'),
        ]);
    }

    private function answer(Workspace $workspace, ClientTimeEntry $entry, User $actor): ClientTimeEntry
    {
        $fresh = $entry->fresh() ?? $entry;

        return app(DisputeSettlements::class)->settleTime($workspace, $fresh->public_id, $actor, DisputeSettlement::Answered, 'Synthetic reply', AgentApiVersion::for($fresh));
    }

    private function period(ClientCompany $company, ClientAgreement $agreement, string $firstOfMonth): ClientInvoice
    {
        return app(ClientInvoicingService::class)->generateCadencePeriod($company, $agreement->fresh() ?? $agreement, Carbon::parse($firstOfMonth));
    }

    private function onAnyLine(ClientTimeEntry $entry): int
    {
        return DB::table('client_invoice_line_time_entries')->where('client_time_entry_id', $entry->id)->count();
    }

    /**
     * The re-review's scenario: 10 retainer hours, no rollover, no threshold.
     * January's 20-hour A is disputed while January is reconciled, answered
     * afterwards, and disputed again before March is issued. March reconciles
     * February's 20-hour B, and its charge must not depend on A at all.
     */
    public function test_a_redispute_of_answered_historical_work_leaves_no_stale_charge_on_a_later_draft(): void
    {
        // The control: the same world without A.
        [$cw, $cc, $cp, $ca, $co] = $this->world('control', 600);
        $this->hours($cw, $cc, $cp, $co, '2026-02-10', 1200);
        app(InvoiceLifecycleService::class)->issue($this->period($cc, $ca, '2026-02-01'), $cw);
        $control = (int) $this->period($cc, $ca, '2026-03-01')->total_amount;

        [$workspace, $company, $project, $agreement, $owner] = $this->world('redispute', 600);
        $a = $this->hours($workspace, $company, $project, $owner, '2026-01-20', 1200);
        $this->stampDispute($a);
        app(InvoiceLifecycleService::class)->issue($this->period($company, $agreement, '2026-02-01'), $workspace);

        $this->answer($workspace, $a, $owner);
        $this->assertTrue((bool) $a->fresh()->is_deferred, 'Answered after January was reconciled, A waits as deferred work.');

        $this->hours($workspace, $company, $project, $owner, '2026-02-10', 1200);
        $march = $this->period($company, $agreement, '2026-03-01');
        $this->assertSame($control, (int) $march->total_amount, 'Waiting deferred work takes no capacity from February.');
        $this->assertSame(0, $this->onAnyLine($a->fresh()));

        $this->stampDispute($a);
        app(DisputedDraftRebuilder::class)->withdraw($workspace->id, [$a->id], []);
        $march = $march->fresh();
        $this->assertSame($control, (int) $march->total_amount);
        app(InvoiceLifecycleService::class)->issue($march, $workspace);
        $this->assertSame('issued', $march->fresh()->status);
    }

    /**
     * The backstop: historical work the ledger reads - time logged late into a
     * reconciled month - disputed after March was generated, with no rebuild.
     * March was priced from a ledger that counted it, so it is not issued until
     * it is regenerated.
     */
    public function test_a_dispute_on_unlinked_historical_work_refuses_a_draft_priced_before_it(): void
    {
        [$workspace, $company, $project, $agreement, $owner] = $this->world('backstop', 600);
        $this->hours($workspace, $company, $project, $owner, '2026-01-10', 60);
        app(InvoiceLifecycleService::class)->issue($this->period($company, $agreement, '2026-02-01'), $workspace);
        // Logged after January was reconciled: unlinked, and in the ledger.
        $late = $this->hours($workspace, $company, $project, $owner, '2026-01-25', 900);
        $this->hours($workspace, $company, $project, $owner, '2026-02-10', 600);
        $march = $this->period($company, $agreement, '2026-03-01');
        $this->assertNotNull($march->held_work_basis);

        $this->stampDispute($late);
        try {
            app(InvoiceLifecycleService::class)->issue($march->fresh(), $workspace);
            $this->fail('A draft priced before the dispute was issued.');
        } catch (DisputedWorkChanged $refused) {
            $this->assertSame('disputed_work_changed', $refused->refusalCode());
        }
        $this->assertSame('draft', $march->fresh()->status);

        $rebuilt = app(ClientInvoicingService::class)->regenerateDraftInvoice($march->fresh());
        app(InvoiceLifecycleService::class)->issue($rebuilt->fresh(), $workspace);
        $this->assertSame('issued', $rebuilt->fresh()->status);
    }

    /** The same dispute, with the rebuild that follows it: March is repriced and issues. */
    public function test_the_rebuild_after_a_dispute_reprices_every_later_draft_that_depends_on_it(): void
    {
        [$workspace, $company, $project, $agreement, $owner] = $this->world('dependents', 600);
        $this->hours($workspace, $company, $project, $owner, '2026-01-10', 60);
        app(InvoiceLifecycleService::class)->issue($this->period($company, $agreement, '2026-02-01'), $workspace);
        $late = $this->hours($workspace, $company, $project, $owner, '2026-01-25', 900);
        $this->hours($workspace, $company, $project, $owner, '2026-02-10', 1200);
        $march = $this->period($company, $agreement, '2026-03-01');
        $before = (int) $march->total_amount;

        $this->stampDispute($late);
        app(DisputedDraftRebuilder::class)->withdraw($workspace->id, [$late->id], []);

        $march = $march->fresh();
        $this->assertLessThan($before, (int) $march->total_amount, 'Without the disputed hours, January carries no debt into February.');
        app(InvoiceLifecycleService::class)->issue($march, $workspace);
        $this->assertSame('issued', $march->fresh()->status);
    }

    public function test_an_undisturbed_draft_issues_and_one_generated_before_the_basis_existed_is_not_refused(): void
    {
        [$workspace, $company, $project, $agreement, $owner] = $this->world('undisturbed', 600);
        $this->hours($workspace, $company, $project, $owner, '2026-01-10', 60);
        $january = $this->period($company, $agreement, '2026-02-01');
        $this->assertSame(1, $january->held_work_basis['version'] ?? null);
        app(InvoiceLifecycleService::class)->issue($january, $workspace);
        $this->assertSame('issued', $january->fresh()->status);

        $this->hours($workspace, $company, $project, $owner, '2026-02-10', 60);
        $legacy = $this->period($company, $agreement, '2026-03-01');
        $legacy->forceFill(['held_work_basis' => null])->save();
        $this->stampDispute($this->hours($workspace, $company, $project, $owner, '2026-01-26', 60));
        app(InvoiceLifecycleService::class)->issue($legacy->fresh(), $workspace);
        $this->assertSame('issued', $legacy->fresh()->status);
    }

    /** No retainer: the answered hour waits in the backlog for a buy-down, and the draft says so. */
    public function test_a_late_answer_on_an_hourly_agreement_joins_the_deferred_backlog(): void
    {
        [$workspace, $company, $project, $agreement, $owner] = $this->world('hourly', 0);
        $a = $this->hours($workspace, $company, $project, $owner, '2026-01-20', 60);
        $this->hours($workspace, $company, $project, $owner, '2026-01-21', 60);
        $this->stampDispute($a);
        $january = $this->period($company, $agreement, '2026-02-01');
        $this->assertSame(10000, (int) $january->total_amount, 'Only B is billed while A is held.');
        app(InvoiceLifecycleService::class)->issue($january, $workspace);

        $this->answer($workspace, $a, $owner);
        $this->assertTrue((bool) $a->fresh()->is_deferred);
        $this->assertSame(1, ClientTimeEntry::query()->whereKey($a->id)->awaitingDeferredBilling()->count());

        $this->hours($workspace, $company, $project, $owner, '2026-02-10', 60);
        $february = $this->period($company, $agreement, '2026-03-01');
        $this->assertSame(0, $this->onAnyLine($a->fresh()), 'With no capacity it waits for a buy-down.');
        $this->assertContains($a->id, array_column($february->fresh()->deferred_work_statement['entries'] ?? [], 'id'), 'The draft lists it as still deferred.');
    }

    /** A retainer with room: a later draft's free capacity takes the deferred hour, once. */
    public function test_a_late_answer_is_drawn_once_from_later_free_capacity(): void
    {
        [$workspace, $company, $project, $agreement, $owner] = $this->world('capacity', 600);
        $a = $this->hours($workspace, $company, $project, $owner, '2026-01-20', 120);
        $this->stampDispute($a);
        app(InvoiceLifecycleService::class)->issue($this->period($company, $agreement, '2026-02-01'), $workspace);

        $this->answer($workspace, $a, $owner);
        $february = $this->period($company, $agreement, '2026-03-01');

        $this->assertSame(1, $this->onAnyLine($a->fresh()));
        app(ClientInvoicingService::class)->regenerateDraftInvoice($february->fresh());
        $this->assertSame(1, $this->onAnyLine($a->fresh()));
    }

    /** Answered while a draft still covers its period: it goes back on it, not to the backlog. */
    public function test_an_answer_with_its_period_still_on_a_draft_is_not_deferred(): void
    {
        [$workspace, $company, $project, $agreement, $owner] = $this->world('covered', 600);
        $a = $this->hours($workspace, $company, $project, $owner, '2026-01-20', 60);
        $this->stampDispute($a);
        $this->period($company, $agreement, '2026-02-01');

        $this->answer($workspace, $a, $owner);

        $this->assertFalse((bool) $a->fresh()->is_deferred);
        $this->assertSame(1, $this->onAnyLine($a->fresh()));
    }
}
