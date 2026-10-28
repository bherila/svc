<?php

namespace Tests\Unit\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientPortalInvitation;
use App\Models\ClientProjectMembership;
use App\Models\ClientProposal;
use App\Models\ClientProposalItem;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Support\AgentApi\Presenters\AgentClientPresenter;
use App\Support\AgentApi\Presenters\AgentExpensePresenter;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use App\Support\AgentApi\Presenters\AgentPortalInvitationPresenter;
use App\Support\AgentApi\Presenters\AgentProjectMemberPresenter;
use App\Support\AgentApi\Presenters\AgentProposalPresenter;
use App\Support\AgentApi\Presenters\AgentTimeEntryPresenter;
use App\Support\AgentApi\Presenters\AgentUserPresenter;
use Carbon\CarbonImmutable;
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
        $entry = new ClientTimeEntry(['public_id' => 'synthetic-time-id', 'worked_on' => '2026-10-28', 'minutes' => 30, 'description' => 'Synthetic internal-only text',
            'client_visible_description' => null, 'status' => 'approved', 'is_billable' => true, 'is_deferred' => false]);
        $this->assertSame(['id' => 'synthetic-time-id', 'worked_on' => '2026-10-28', 'minutes' => 30, 'description' => 'Synthetic internal-only text', 'status' => 'approved', 'is_billable' => true, 'is_deferred' => false], AgentTimeEntryPresenter::summary($entry));
        $this->assertNull(AgentTimeEntryPresenter::summary($entry, useClientDescription: true)['description']);
        $entry->client_visible_description = 'Synthetic client-approved text';
        $this->assertSame('Synthetic client-approved text', AgentTimeEntryPresenter::summary($entry, useClientDescription: true)['description']);
        $this->assertNull(AgentTimeEntryPresenter::summary($entry, compact: true)['description']);
        $this->assertNull(AgentTimeEntryPresenter::summary($entry, useClientDescription: true, compact: true)['description']);
    }

    public function test_proposal_summary_keeps_decimal_arithmetic_and_existing_timestamp_formats_without_queries(): void
    {
        $proposal = new ClientProposal(['public_id' => 'synthetic-proposal-id', 'summary' => 'Synthetic scope', 'terms' => 'Synthetic terms', 'valid_until' => '2026-11-15', 'title' => 'Synthetic fractional proposal', 'status' => 'sent', 'currency' => 'USD',
            'sent_at' => '2026-10-28 09:10:11', 'accepted_at' => '2026-10-28 10:11:12']);
        $item = new ClientProposalItem(['public_id' => 'synthetic-item-id', 'description' => 'Synthetic fractional work', 'quantity' => '1.125', 'unit_amount' => 2500,
            'cadence' => 'one_time', 'sort_order' => 7]);
        $proposal->setRelation('items', new Collection([$item]));
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame(['id' => 'synthetic-proposal-id', 'title' => 'Synthetic fractional proposal', 'status' => 'sent', 'total_amount' => 2813, 'currency' => 'USD'], AgentProposalPresenter::summary($proposal));
        $this->assertSame(['summary' => 'Synthetic scope', 'terms' => 'Synthetic terms', 'valid_until' => '2026-11-15'], array_intersect_key(AgentProposalPresenter::details($proposal), array_flip(['summary', 'terms', 'valid_until'])));
        $this->assertSame(['id' => 'synthetic-item-id', 'description' => 'Synthetic fractional work', 'quantity' => '1.125', 'unit_amount' => 2500, 'cadence' => 'one_time'], AgentProposalPresenter::item($item));
        $this->assertSame('1.125', AgentProposalPresenter::item($item)['quantity']);
        $this->assertArrayNotHasKey('sort_order', AgentProposalPresenter::item($item));
        $this->assertSame('2026-10-28T09:10:11.000000Z', AgentProposalPresenter::details($proposal)['sent_at']);
        $this->assertSame('2026-10-28T09:10:11+00:00', AgentProposalPresenter::details($proposal, iso8601Timestamps: true)['sent_at']);
        $this->assertSame('2026-10-28T10:11:12.000000Z', AgentProposalPresenter::details($proposal)['accepted_at']);
        $this->assertSame('2026-10-28T10:11:12+00:00', AgentProposalPresenter::details($proposal, iso8601Timestamps: true)['accepted_at']);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_unsent_proposal_timestamps_stay_null_in_both_existing_formats(): void
    {
        $proposal = new ClientProposal(['title' => 'Synthetic unsent proposal', 'status' => 'draft', 'currency' => 'USD']);
        $proposal->setRelation('items', new Collection);
        foreach ([false, true] as $iso) {
            $details = AgentProposalPresenter::details($proposal, iso8601Timestamps: $iso);
            $this->assertNull($details['sent_at']);
            $this->assertNull($details['accepted_at']);
            $this->assertNull($details['valid_until']);
        }
    }

    public function test_access_summaries_expose_only_public_identity_and_never_a_bearer_link(): void
    {
        $this->travelTo(new CarbonImmutable('2026-10-28 10:00:00 UTC'));
        $user = (new User)->forceFill(['public_id' => 'synthetic-user-id', 'name' => 'Synthetic Member', 'email' => 'private@example.test']);
        $this->assertSame(['id' => 'synthetic-user-id', 'name' => 'Synthetic Member'], AgentUserPresenter::identity($user));
        $grant = new ClientProjectMembership(['role' => 'manager']);
        $this->assertSame(['user_id' => 'synthetic-user-id', 'role' => 'manager'], AgentProjectMemberPresenter::grant('synthetic-user-id', $grant));
        $this->assertSame(['user_id' => 'synthetic-user-id', 'role' => 'none'], AgentProjectMemberPresenter::grant('synthetic-user-id', null));
        $invitation = (new ClientPortalInvitation)->forceFill(['public_id' => 'synthetic-invitation-id', 'email' => 'invite@example.test',
            'access_scope' => 'projects', 'status' => 'pending', 'email_sent' => true, 'expires_at' => '2026-10-29 12:13:14',
            'link' => 'https://auth.example.test/synthetic-bearer-link']);
        $expected = ['id' => 'synthetic-invitation-id', 'email' => 'invite@example.test', 'scope' => 'projects', 'status' => 'pending',
            'expires_at' => '2026-10-29T12:13:14+00:00', 'email_sent' => true];
        $this->assertSame($expected, AgentPortalInvitationPresenter::summary($invitation));
        $expected['expires_at'] = '2026-10-29T12:13:14Z';
        $this->assertSame($expected, AgentPortalInvitationPresenter::summary($invitation, zuluTimestamps: true));
        $invitation->expires_at = null;
        $this->assertNull(AgentPortalInvitationPresenter::summary($invitation)['expires_at']);
        $this->assertNull(AgentPortalInvitationPresenter::summary($invitation, zuluTimestamps: true)['expires_at']);
    }

    public function test_receipt_expense_summary_excludes_finance_and_relation_metadata(): void
    {
        $expense = new ClientExpense(['description' => 'Synthetic receipt expense', 'spent_on' => '2026-10-28', 'amount' => 1234,
            'currency' => 'USD', 'status' => 'draft', 'external_finance_transaction_uuid' => 'synthetic-private-finance-id']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame(['description' => 'Synthetic receipt expense', 'spent_on' => '2026-10-28', 'amount' => 1234, 'currency' => 'USD', 'status' => 'draft'], AgentExpensePresenter::summary($expense));
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_proposal_projection_refuses_implicit_item_queries(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Load the scoped proposal items before presenting a proposal.');
        AgentProposalPresenter::summary(new ClientProposal);
    }
}
