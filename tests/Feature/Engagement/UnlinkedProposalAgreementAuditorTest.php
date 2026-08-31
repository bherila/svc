<?php

namespace Tests\Feature\Engagement;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProposal;
use App\Models\Workspace;
use App\Services\Engagement\UnlinkedProposalAgreementAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the audit counts, and whose.
 *
 * The funnel tests pin the arithmetic; the scoping tests pin the tenancy
 * boundary. Both matter for the same reason they do on
 * `UnplaceableInvoiceAuditorTest`: the console runs unscoped on purpose, so
 * nothing there would notice if the workspace parameter silently did nothing,
 * and a tenant-facing surface built on top would report one client's
 * data-quality problem to another.
 */
final class UnlinkedProposalAgreementAuditorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every stage narrows, and each narrowing removes a different row.
     *
     * Built so consecutive stages never agree - 7 proposals, then 4, 3, 2 -
     * because a funnel that skipped a stage would report identical totals for
     * both and nothing would notice. Each dropped row is dropped by exactly one
     * condition, so removing that condition changes exactly one number.
     */
    public function test_each_stage_of_the_sent_funnel_narrows(): void
    {
        $workspace = $this->workspace('funnel');
        $company = $this->company($workspace, 'funnel');

        // The company has one active agreement naming no proposal - the thing a
        // lost link would have pointed at, and the thing a second acceptance
        // would duplicate.
        $this->agreement($workspace, $company, null, 'active');

        // Counted to the end: sent, unlinked, company has an active unlinked
        // agreement.
        $this->proposal($workspace, $company, 'sent');
        $this->proposal($workspace, $company, 'sent');

        // Dropped at the last stage. Its company's only unlinked agreement is
        // cancelled, so a duplicate would not bill alongside a live one.
        $expired = $this->company($workspace, 'expired');
        $this->agreement($workspace, $expired, null, 'cancelled');
        $this->proposal($workspace, $expired, 'sent');

        // Dropped one stage earlier. Its company has no unlinked agreement at
        // all, so accepting it creates the first agreement - correct, not this
        // defect. The agreement present names a *different* proposal, which is
        // accounted for and cannot be a lost link.
        $linkedElsewhere = $this->company($workspace, 'linked-elsewhere');
        $other = $this->proposal($workspace, $linkedElsewhere, 'accepted');
        $this->agreement($workspace, $linkedElsewhere, $other, 'active');
        $this->proposal($workspace, $linkedElsewhere, 'sent');

        // Dropped at the first stage: sent, but its own agreement is linked, so
        // the guard in acceptance can see it and returns it instead of creating.
        $linked = $this->company($workspace, 'linked');
        $sound = $this->proposal($workspace, $linked, 'sent');
        $this->agreement($workspace, $linked, $sound, 'active');

        // Not sent at all, and separately reported.
        $this->proposal($workspace, $company, 'accepted');

        $counts = app(UnlinkedProposalAgreementAuditor::class)->count($workspace);

        $this->assertSame(7, $counts->proposals);
        $this->assertSame(4, $counts->sentWithoutALinkedAgreement);
        $this->assertSame(3, $counts->withAnUnlinkedAgreementOnTheCompany);
        $this->assertSame(2, $counts->withAnActiveUnlinkedAgreement);
        $this->assertTrue($counts->isLive());
    }

    /**
     * The accepted population is reported and not folded into the sent one.
     *
     * These are inert today - acceptance returns early for an already-accepted
     * proposal and creates nothing - so they must not raise the live count. The
     * assertion that `isLive()` stays false with two of them present is the one
     * that would fail if the two populations were ever summed.
     */
    public function test_accepted_proposals_are_counted_apart_and_do_not_make_it_live(): void
    {
        $workspace = $this->workspace('accepted');
        $company = $this->company($workspace, 'accepted');
        $this->agreement($workspace, $company, null, 'active');

        $this->proposal($workspace, $company, 'accepted');
        $this->proposal($workspace, $company, 'accepted');

        $counts = app(UnlinkedProposalAgreementAuditor::class)->count($workspace);

        $this->assertSame(2, $counts->acceptedWithoutALinkedAgreement);
        $this->assertSame(0, $counts->sentWithoutALinkedAgreement);
        $this->assertSame(0, $counts->withAnActiveUnlinkedAgreement);
        $this->assertFalse($counts->isLive());
    }

    /**
     * A draft proposal is neither population.
     *
     * Asserted because the two counted statuses are named explicitly and a
     * fourth status exists: a filter written as "not accepted" rather than "is
     * sent" would sweep drafts and declined proposals into the live count, and
     * acceptance refuses both outright.
     */
    public function test_a_draft_or_declined_proposal_is_in_neither_population(): void
    {
        $workspace = $this->workspace('draft');
        $company = $this->company($workspace, 'draft');
        $this->agreement($workspace, $company, null, 'active');

        $this->proposal($workspace, $company, 'draft');
        $this->proposal($workspace, $company, 'declined');

        $counts = app(UnlinkedProposalAgreementAuditor::class)->count($workspace);

        $this->assertSame(2, $counts->proposals);
        $this->assertSame(0, $counts->sentWithoutALinkedAgreement);
        $this->assertSame(0, $counts->acceptedWithoutALinkedAgreement);
        $this->assertFalse($counts->isLive());
    }

    /**
     * An agreement in another workspace is not this proposal's link.
     *
     * The relationship filters on the foreign key alone, but `source_proposal_id`
     * is unconstrained across tenants in the same way `client_agreement_id` is
     * on invoices. A cross-tenant row that satisfied the link would make a
     * genuinely exposed proposal look sound, which is the one direction of error
     * this audit must not make: it would report zero and be believed.
     */
    public function test_a_link_from_another_workspace_does_not_count_as_linked(): void
    {
        $workspace = $this->workspace('own');
        $company = $this->company($workspace, 'own');
        $this->agreement($workspace, $company, null, 'active');
        $proposal = $this->proposal($workspace, $company, 'sent');

        $neighbour = $this->workspace('neighbour');
        $neighbourCompany = $this->company($neighbour, 'neighbour');
        $this->agreement($neighbour, $neighbourCompany, $proposal, 'active');

        $counts = app(UnlinkedProposalAgreementAuditor::class)->count($workspace);

        $this->assertSame(1, $counts->sentWithoutALinkedAgreement);
        $this->assertTrue($counts->isLive());
    }

    /**
     * A company's agreements do not answer for a company in another workspace.
     *
     * The candidate lookup matches on company, and company ids are unique
     * globally, so this could only fail if that lookup dropped its workspace
     * column. It is asserted anyway because the failure would be silent and in
     * the overstating direction, and because the isolation harness asserts
     * every read surface with a second workspace rather than a happy path.
     */
    public function test_a_scoped_audit_sees_only_its_own_workspace(): void
    {
        $mine = $this->exposedProposalIn('first');
        $this->exposedProposalIn('second');

        $counts = app(UnlinkedProposalAgreementAuditor::class)->count($mine);

        $this->assertSame(1, $counts->proposals);
        $this->assertSame(1, $counts->withAnActiveUnlinkedAgreement);
        $this->assertSame(1, $counts->unlinkedAgreements);
    }

    public function test_an_unscoped_audit_counts_every_workspace(): void
    {
        $this->exposedProposalIn('first');
        $this->exposedProposalIn('second');

        $counts = app(UnlinkedProposalAgreementAuditor::class)->count();

        $this->assertSame(2, $counts->proposals);
        $this->assertSame(2, $counts->withAnActiveUnlinkedAgreement);
        $this->assertSame(2, $counts->unlinkedAgreements);
    }

    /**
     * A clean workspace reports nothing while its neighbour is broken.
     *
     * Asserted separately because a scope that leaked would still pass the test
     * above by coincidence: both workspaces are exposed there, so a wrong number
     * is a plausible number.
     */
    public function test_a_clean_workspace_reports_nothing_when_its_neighbour_is_broken(): void
    {
        $clean = $this->workspace('clean');
        $this->exposedProposalIn('broken');

        $counts = app(UnlinkedProposalAgreementAuditor::class)->count($clean);

        $this->assertSame(0, $counts->proposals);
        $this->assertSame(0, $counts->sentWithoutALinkedAgreement);
        $this->assertSame(0, $counts->withAnActiveUnlinkedAgreement);
        $this->assertSame(0, $counts->unlinkedAgreements);
        $this->assertFalse($counts->isLive());
    }

    private function workspace(string $slug): Workspace
    {
        return Workspace::query()->create([
            'name' => ucfirst($slug).' Workspace',
            'slug' => $slug.'-workspace',
        ]);
    }

    private function company(Workspace $workspace, string $slug): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => ucfirst($slug).' Client',
            'slug' => $slug.'-client',
        ]);
    }

    private function proposal(Workspace $workspace, ClientCompany $company, string $status): ClientProposal
    {
        $proposal = ClientProposal::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Proposal',
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        // forceFill because the audit's subject is proposals sitting in states a
        // normal write path reaches only through the workflow, and going through
        // the workflow would create the very agreement whose absence is the
        // thing being counted.
        $proposal->forceFill(['status' => $status])->save();

        return $proposal;
    }

    private function agreement(
        Workspace $workspace,
        ClientCompany $company,
        ?ClientProposal $proposal,
        string $status,
    ): ClientAgreement {
        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'source_proposal_id' => $proposal?->id,
            'title' => 'Retainer',
            'status' => $status,
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
        ]);
    }

    /**
     * One sent proposal whose company carries an active agreement naming no
     * proposal - the shape the audit exists to find - in a workspace of its own.
     */
    private function exposedProposalIn(string $slug): Workspace
    {
        $workspace = $this->workspace($slug);
        $company = $this->company($workspace, $slug);

        $this->agreement($workspace, $company, null, 'active');
        $this->proposal($workspace, $company, 'sent');

        return $workspace;
    }
}
