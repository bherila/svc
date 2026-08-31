<?php

namespace Tests\Feature\Engagement;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProposal;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * What the command prints, and what it must never print.
 *
 * `UnlinkedProposalAgreementAuditorTest` covers the arithmetic. This covers the
 * two things only the command can get wrong: the shape of the `--format=json`
 * contract, which something downstream will parse, and the promise that the
 * output is safe to paste into a public issue.
 *
 * The safety assertion is the important one. It is stated as an absence, and an
 * absence is exactly the kind of claim that passes by accident, so the fixture
 * is named with strings that appear nowhere else in the codebase and the test
 * asserts a control string is present alongside them being missing - proving the
 * output was captured at all.
 */
final class AuditUnlinkedProposalAgreementsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_a_format_it_cannot_produce(): void
    {
        $this->artisan('svc:engagement:audit-unlinked-proposal-agreements', ['--format' => 'yaml'])
            ->expectsOutputToContain('The --format option must be text or json.')
            ->assertExitCode(2);
    }

    /**
     * The json shape, key by key.
     *
     * Asserted as an exact key set rather than a subset: a consumer reading
     * this is parsing a fixed contract, and a key silently disappearing is the
     * failure that would otherwise reach them instead of CI.
     */
    public function test_the_json_contract_is_the_full_set_of_counts(): void
    {
        $this->exposedProposal();

        Artisan::call('svc:engagement:audit-unlinked-proposal-agreements', ['--format' => 'json']);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'proposals',
            'sent_without_a_linked_agreement',
            'with_an_unlinked_agreement_on_the_company',
            'with_an_active_unlinked_agreement',
            'accepted_without_a_linked_agreement',
            'unlinked_agreements',
        ], array_keys($payload['summary']));

        $this->assertSame(1, $payload['summary']['with_an_active_unlinked_agreement']);
    }

    /**
     * It says nothing that identifies a client.
     *
     * The whole reason the counts live in a readonly value object is that a
     * caller cannot leak an identifier through it. This asserts the property
     * end to end, in both formats, because that guarantee is what makes the
     * output safe to paste into a public issue against real billing records.
     */
    public function test_neither_format_prints_anything_that_identifies_a_client(): void
    {
        $this->exposedProposal();

        foreach (['text', 'json'] as $format) {
            Artisan::call('svc:engagement:audit-unlinked-proposal-agreements', ['--format' => $format]);
            $output = Artisan::output();

            // The control: proves the output was captured, so the absences
            // below are absences from something rather than from an empty
            // string.
            $this->assertStringContainsString('1', $output);

            foreach ([
                'Zeugma Holdings',      // company name
                'zeugma-holdings',      // company slug
                'Tessellate Retainer',  // agreement title
                'Anaphora Engagement',  // proposal title
                'zeugma-workspace',     // workspace slug
            ] as $identifier) {
                $this->assertStringNotContainsString($identifier, $output, "$format output named $identifier");
            }
        }
    }

    /**
     * The warning fires on the live population and the clean line does not.
     *
     * Both directions, because a command that always warns and a command that
     * never warns are equally useless and only one of them looks broken.
     */
    public function test_it_warns_only_when_a_sent_proposal_can_duplicate_a_live_agreement(): void
    {
        $this->artisan('svc:engagement:audit-unlinked-proposal-agreements')
            ->expectsOutputToContain('No sent proposal can create a duplicate contract')
            ->assertExitCode(0);

        $this->exposedProposal();

        $this->artisan('svc:engagement:audit-unlinked-proposal-agreements')
            ->expectsOutputToContain('writes a second agreement')
            ->assertExitCode(0);
    }

    /**
     * An inert accepted proposal is reported without being called live.
     *
     * The two notices are independent, and folding them together is the
     * mistake this pins: the accepted population is real and worth reporting,
     * and reporting it as a duplicate risk would make the live warning
     * meaningless.
     */
    public function test_an_accepted_proposal_is_reported_without_raising_the_live_warning(): void
    {
        $workspace = $this->workspace();
        $company = $this->company($workspace);
        $this->agreement($workspace, $company, null);

        ClientProposal::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Anaphora Engagement',
            'currency' => 'USD',
            'status' => 'draft',
        ])->forceFill(['status' => 'accepted'])->save();

        $this->artisan('svc:engagement:audit-unlinked-proposal-agreements')
            ->expectsOutputToContain('No sent proposal can create a duplicate contract')
            ->expectsOutputToContain('inert today')
            ->assertExitCode(0);
    }

    private function workspace(): Workspace
    {
        return Workspace::query()->create([
            'name' => 'Zeugma Workspace',
            'slug' => 'zeugma-workspace',
        ]);
    }

    private function company(Workspace $workspace): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Zeugma Holdings',
            'slug' => 'zeugma-holdings',
        ]);
    }

    private function agreement(Workspace $workspace, ClientCompany $company, ?ClientProposal $proposal): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'source_proposal_id' => $proposal?->id,
            'title' => 'Tessellate Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
        ]);
    }

    /**
     * One sent proposal on a company holding an active agreement that names no
     * proposal: the exact state in which accepting creates a second contract.
     */
    private function exposedProposal(): void
    {
        $workspace = $this->workspace();
        $company = $this->company($workspace);

        $this->agreement($workspace, $company, null);

        ClientProposal::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Anaphora Engagement',
            'currency' => 'USD',
            'status' => 'draft',
        ])->forceFill(['status' => 'sent'])->save();
    }
}
