<?php

namespace Tests\Unit\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProposal;
use App\Models\ClientProposalItem;
use App\Models\ClientTimeEntry;
use App\Support\AgentApi\Presenters\AgentClientPresenter;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use App\Support\AgentApi\Presenters\AgentProposalPresenter;
use App\Support\AgentApi\Presenters\AgentTimeEntryPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class SharedReadProjectionTest extends TestCase
{
    public function test_public_summaries_do_not_resolve_relations_or_include_internal_envelope_fields(): void
    {
        $company = new ClientCompany(['name' => 'Synthetic Client', 'billing_email' => 'billing@example.test', 'is_active' => true,
            'internal_notes' => 'Synthetic manager-only note']);
        $invoice = new ClientInvoice(['invoice_number' => 'SYN-READ', 'status' => 'issued', 'currency' => 'USD',
            'total_amount' => 1250, 'paid_amount' => 250, 'balance_amount' => 1000, 'notes' => 'Synthetic invoice secret']);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame(['id', 'name'], array_keys(AgentClientPresenter::identity($company)));
        $this->assertSame(['id', 'name', 'billing_email', 'is_active'], array_keys(AgentClientPresenter::summary($company)));
        $this->assertSame(['id', 'invoice_number', 'status', 'total_amount', 'paid_amount', 'balance_amount', 'currency', 'issue_date', 'due_date'], array_keys(AgentInvoicePresenter::summary($invoice)));
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_time_summary_requires_an_explicit_client_description_policy_and_never_falls_back_to_internal_text(): void
    {
        $entry = new ClientTimeEntry(['worked_on' => '2026-10-28', 'minutes' => 30, 'description' => 'Synthetic internal-only text',
            'client_visible_description' => null, 'status' => 'approved', 'is_billable' => true, 'is_deferred' => false]);
        $this->assertSame('Synthetic internal-only text', AgentTimeEntryPresenter::summary($entry)['description']);
        $this->assertNull(AgentTimeEntryPresenter::summary($entry, useClientDescription: true)['description']);
        $entry->client_visible_description = 'Synthetic client-approved text';
        $this->assertSame('Synthetic client-approved text', AgentTimeEntryPresenter::summary($entry, useClientDescription: true)['description']);
        $this->assertNull(AgentTimeEntryPresenter::summary($entry, compact: true)['description']);
        $this->assertNull(AgentTimeEntryPresenter::summary($entry, useClientDescription: true, compact: true)['description']);
    }

    public function test_proposal_summary_keeps_decimal_arithmetic_and_existing_timestamp_formats_without_queries(): void
    {
        $proposal = new ClientProposal(['title' => 'Synthetic fractional proposal', 'status' => 'sent', 'currency' => 'USD',
            'sent_at' => '2026-10-28 09:10:11', 'accepted_at' => '2026-10-28 10:11:12']);
        $item = new ClientProposalItem(['description' => 'Synthetic fractional work', 'quantity' => '1.125', 'unit_amount' => 2500,
            'cadence' => 'one_time', 'sort_order' => 7]);
        $proposal->setRelation('items', new Collection([$item]));
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame(2813, AgentProposalPresenter::summary($proposal)['total_amount']);
        $this->assertSame('1.125', AgentProposalPresenter::item($item)['quantity']);
        $this->assertArrayNotHasKey('sort_order', AgentProposalPresenter::item($item));
        $this->assertSame('2026-10-28T09:10:11.000000Z', AgentProposalPresenter::details($proposal)['sent_at']);
        $this->assertSame('2026-10-28T09:10:11+00:00', AgentProposalPresenter::details($proposal, iso8601Timestamps: true)['sent_at']);
        $this->assertSame('2026-10-28T10:11:12.000000Z', AgentProposalPresenter::details($proposal)['accepted_at']);
        $this->assertSame('2026-10-28T10:11:12+00:00', AgentProposalPresenter::details($proposal, iso8601Timestamps: true)['accepted_at']);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_proposal_projection_refuses_implicit_item_queries(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Load the scoped proposal items before presenting a proposal.');
        AgentProposalPresenter::summary(new ClientProposal);
    }
}
