<?php

namespace Tests\Unit\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingEligibilityOrder;
use App\Services\Billing\LateWork;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(LateWork::class)]
final class LateWorkOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_classification_preserves_period_boundaries_ordering_and_legacy_evidence(): void
    {
        [$agreement, $invoice, $entry] = $this->fixture();
        $order = app(BillingEligibilityOrder::class);
        $earlier = $order->record($agreement->workspace_id, $agreement->client_company_id);
        $later = $order->record($agreement->workspace_id, $agreement->client_company_id);
        $before = '2026-02-01 08:59:59';
        $same = '2026-02-01 09:00:00';
        $after = '2026-02-01 09:00:01';
        // One fixture deliberately covers the complete decision matrix without
        // rebuilding the application/database for every timestamp combination.
        $cases = [
            'created after' => ['2026-01-15', $after, null, null, null, true],
            'created equal' => ['2026-01-15', $same, null, null, null, false],
            'created before' => ['2026-01-15', $before, null, null, null, false],
            'approved after' => ['2026-01-15', $before, $after, null, null, true],
            'approved equal' => ['2026-01-15', $before, $same, null, null, false],
            'approved before' => ['2026-01-15', $before, $before, null, null, false],
            'approval without creation' => ['2026-01-15', null, $after, null, null, true],
            'no timestamp evidence' => ['2026-01-15', null, null, null, null, false],
            'first covered day' => ['2026-01-01', $after, null, null, null, true],
            'last covered day' => ['2026-01-31', $after, null, null, null, true],
            'before covered period' => ['2025-12-31', $after, null, null, null, false],
            'after covered period' => ['2026-02-01', $after, null, null, null, false],
            'ordered later at equal second' => ['2026-01-15', $same, $same, $later, $earlier, true],
            'ordered earlier overrides later clock' => ['2026-01-15', $after, $after, $earlier, $later, false],
            'entry order only uses legacy equal' => ['2026-01-15', $same, $same, $later, null, false],
            'entry order only uses legacy after' => ['2026-01-15', $after, null, $later, null, true],
            'invoice order only uses legacy equal' => ['2026-01-15', $same, $same, null, $earlier, false],
            'invoice order only uses legacy after' => ['2026-01-15', null, $after, null, $earlier, true],
        ];
        foreach ($cases as $label => [$day, $created, $approved, $entryEvent, $issueEvent, $late]) {
            ClientTimeEntry::query()->where('workspace_id', $agreement->workspace_id)->whereKey($entry->id)->update([
                'worked_on' => $day, 'created_at' => $created, 'approved_at' => $approved,
                'billing_eligibility_event_id' => $entryEvent,
            ]);
            $invoice->forceFill(['billing_eligibility_event_id' => $issueEvent])->save();
            $this->assertSame($late ? [$entry->id] : [], $this->ids($agreement), $label);
        }
        foreach (['service_period_start', 'service_period_end', 'issued_at'] as $column) {
            $original = $invoice->getRawOriginal($column);
            $invoice->forceFill([$column => null])->save();
            $this->assertSame([], $this->ids($agreement), $column.' must be admitted before classification');
            $invoice->forceFill([$column => $original])->save();
        }
    }

    public function test_split_shared_events_are_deduplicated_and_filtered_ids_remain_a_list(): void
    {
        [$agreement, $invoice, $entry] = $this->fixture();
        $order = app(BillingEligibilityOrder::class);
        $issued = $order->record($agreement->workspace_id, $agreement->client_company_id);
        $arrived = $order->record($agreement->workspace_id, $agreement->client_company_id);
        $invoice->forceFill(['billing_eligibility_event_id' => $issued])->save();
        $entry->update(['worked_on' => '2025-12-31', 'billing_eligibility_event_id' => $arrived]);
        $second = $entry->replicate(['public_id']);
        $second->worked_on = '2026-01-15';
        $second->save();
        $third = $second->replicate(['public_id']);
        $third->save();

        $this->assertSame([$second->id, $third->id], $this->ids($agreement));
    }

    /** @return list<int> */
    private function ids(ClientAgreement $agreement): array
    {
        return (new LateWork)->ids(ClientTimeEntry::query()->orderBy('id'), $agreement, Carbon::parse('2026-03-01'));
    }

    /** @return array{ClientAgreement, ClientInvoice, ClientTimeEntry} */
    private function fixture(): array
    {
        $this->travelTo(Carbon::parse('2026-02-02 12:00:00'));
        $workspace = Workspace::query()->create(['name' => 'Synthetic ordering', 'slug' => 'synthetic-ordering']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'title' => 'Synthetic agreement', 'status' => 'active', 'starts_on' => '2026-01-01', 'currency' => 'USD',
        ]);
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'invoice_number' => 'SYNTHETIC-JAN', 'status' => 'paid', 'invoice_kind' => 'cadence_period', 'currency' => 'USD',
            'service_period_start' => '2026-01-01', 'service_period_end' => '2026-01-31', 'issued_at' => '2026-02-01 09:00:00',
        ]);
        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => User::factory()->create()->id, 'worked_on' => '2026-01-15', 'minutes' => 60,
            'description' => 'Synthetic ordering work', 'status' => 'approved', 'is_billable' => true, 'currency' => 'USD',
        ]);

        return [$agreement, $invoice, $entry];
    }
}
