<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * What the command prints, and what it must never print.
 *
 * `UndatedAgreementAuditorTest` covers the arithmetic. This covers what only the
 * command can get wrong: the `--format=json` shape, the three notices, and the
 * promise that the output is safe to paste into a public issue.
 *
 * The three notices matter more here than on the other audits, because this
 * command reports a bracket. Saying "no entry is provably affected" and saying
 * "nothing is affected" are different claims, and the second would be false -
 * so the wording of the not-proven branch is asserted rather than assumed.
 */
final class AuditUndatedAgreementsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_a_format_it_cannot_produce(): void
    {
        $this->artisan('svc:billing:audit-undated-agreements', ['--format' => 'yaml'])
            ->expectsOutputToContain('The --format option must be text or json.')
            ->assertExitCode(2);
    }

    /**
     * The json shape, key by key and value by value.
     *
     * An exact key set because a consumer parses a fixed contract, and exact
     * values because the fixture makes the fields differ - a mapping that named
     * the right keys against the wrong properties changes a number here.
     */
    public function test_the_json_contract_is_the_full_set_of_counts(): void
    {
        $this->exposed();

        Artisan::call('svc:billing:audit-undated-agreements', ['--format' => 'json']);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'agreements',
            'undated',
            'by_status',
            'by_cadence',
            'hourly_only',
            'with_retainer_terms',
            'entries_with_an_undated_candidate',
            'entries_with_no_other_candidate',
            'billed_lines_on_an_undated_agreement',
        ], array_keys($payload['summary']));

        $this->assertSame(2, $payload['summary']['agreements']);
        $this->assertSame(1, $payload['summary']['undated']);
        $this->assertSame(1, $payload['summary']['hourly_only']);
        $this->assertSame(0, $payload['summary']['with_retainer_terms']);
        $this->assertSame(['active' => 1], $payload['summary']['by_status']);
        $this->assertSame(['one_time' => 1], $payload['summary']['by_cadence']);
    }

    /**
     * It says nothing that identifies a client.
     *
     * The status and cadence breakdowns are the reason this is asserted rather
     * than inherited from the other audits: they are the first audit output
     * keyed by a column value rather than a fixed field name, and a grouping
     * keyed by the wrong column would put titles or slugs straight into a public
     * issue.
     */
    public function test_neither_format_prints_anything_that_identifies_a_client(): void
    {
        $this->exposed();

        foreach (['text', 'json'] as $format) {
            Artisan::call('svc:billing:audit-undated-agreements', ['--format' => $format]);
            $output = Artisan::output();

            // The control: proves the output was captured, so the absences
            // below are absences from something rather than from an empty
            // string.
            $this->assertStringContainsString('1', $output);

            foreach ([
                'Palimpsest Foundry',   // company name
                'palimpsest-foundry',   // company slug
                'Cartouche Retainer',   // agreement title
                'Palimpsest Rebuild',   // project name
                'palimpsest-workspace', // workspace slug
            ] as $identifier) {
                $this->assertStringNotContainsString($identifier, $output, "$format output named $identifier");
            }
        }
    }

    /**
     * Nothing undated at all is reported as latent, not as correct.
     *
     * The readers still disagree with each other; there is simply no row
     * exercising the disagreement. Saying so is the difference between "#147 is
     * fixed" and "#147 has not bitten yet".
     */
    public function test_it_reports_latent_when_no_agreement_is_undated(): void
    {
        $this->artisan('svc:billing:audit-undated-agreements')
            ->expectsOutputToContain('#147 is latent')
            ->assertExitCode(0);
    }

    /**
     * A provably-priced entry raises the live warning, and it points at the
     * decision rather than at a fix.
     */
    public function test_it_warns_when_an_entry_is_certainly_priced_by_an_undated_agreement(): void
    {
        $this->exposed();

        $output = $this->text();

        $this->assertStringContainsString('nothing else eligible', $output);
        $this->assertStringContainsString('Decide the contract in #147 before changing any of them', $output);
        $this->assertStringNotContainsString('#147 is latent', $output);
    }

    /**
     * An undated agreement that a dated one competes with is reported as not
     * proven, and explicitly not as safe.
     *
     * This is the branch worth pinning by its exact words. The audit cannot say
     * which agreement the resolver picks when both are eligible, and a reader
     * who took silence for safety would conclude the opposite of what the
     * numbers support.
     */
    public function test_a_contested_entry_is_reported_as_not_proven_rather_than_safe(): void
    {
        $workspace = $this->workspace();
        $company = $this->company($workspace);
        $project = $this->project($workspace, $company);

        $this->agreement($workspace, ['starts_on' => null, 'hourly_rate_amount' => 15000], $company);
        $this->agreement($workspace, ['starts_on' => '2020-01-01', 'hourly_rate_amount' => 20000], $company);
        $this->entry($workspace, $company, $project);

        $output = $this->text();

        $this->assertStringContainsString('No entry is provably priced by an undated agreement', $output);
        $this->assertStringContainsString('not as "safe"', $output);
        $this->assertStringNotContainsString('#147 is latent', $output);
    }

    /**
     * The already-billed notice is independent of the live one.
     *
     * They answer different questions - what happens next time, and what has
     * already happened - so a fixture that triggers only the second must still
     * see it.
     */
    public function test_billed_lines_are_reported_even_when_nothing_is_provably_live(): void
    {
        $workspace = $this->workspace();
        $company = $this->company($workspace);
        $agreement = $this->agreement($workspace, ['starts_on' => null, 'hourly_rate_amount' => 15000], $company);

        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => 'PAL-0001',
            'status' => 'issued',
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        ClientInvoiceLine::query()->create([
            'workspace_id' => $workspace->id,
            'client_invoice_id' => $invoice->id,
            'client_agreement_id' => $agreement->id,
            'type' => 'time',
            'description' => 'Work under an undated agreement',
            'quantity' => '1.000',
            'unit_amount' => 15000,
            'total_amount' => 15000,
        ]);

        $output = $this->text();

        $this->assertStringContainsString('already billed against an undated agreement', $output);
        $this->assertStringContainsString('does not unbill them', $output);
    }

    /**
     * The text output with its console line-wrapping flattened.
     *
     * The notices are several sentences long and the component wraps them to
     * the terminal, so a phrase asserted verbatim can straddle a newline and
     * fail for reasons that have nothing to do with what the command said.
     */
    private function text(): string
    {
        Artisan::call('svc:billing:audit-undated-agreements');

        return (string) preg_replace('/\s+/', ' ', Artisan::output());
    }

    private function workspace(): Workspace
    {
        return Workspace::query()->create([
            'name' => 'Palimpsest Workspace',
            'slug' => 'palimpsest-workspace',
        ]);
    }

    private function company(Workspace $workspace): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Palimpsest Foundry',
            'slug' => 'palimpsest-foundry',
        ]);
    }

    private function project(Workspace $workspace, ClientCompany $company): ClientProject
    {
        return ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Palimpsest Rebuild',
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function agreement(Workspace $workspace, array $overrides, ClientCompany $company): ClientAgreement
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Cartouche Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
        ]);

        $agreement->forceFill($overrides)->save();

        return $agreement;
    }

    private function entry(Workspace $workspace, ClientCompany $company, ClientProject $project): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-08-01',
            'minutes' => 60,
            'description' => 'Synthetic work',
            'status' => 'draft',
            'currency' => 'USD',
        ]);
    }

    /**
     * One undated agreement with nothing to outrank it, one dated agreement in
     * another company so the totals are not all 1, and one entry it prices.
     */
    private function exposed(): void
    {
        $workspace = $this->workspace();
        $company = $this->company($workspace);
        $project = $this->project($workspace, $company);

        $this->agreement($workspace, ['starts_on' => null, 'hourly_rate_amount' => 15000], $company);

        $other = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Unrelated Client',
            'slug' => 'unrelated-client',
        ]);
        $this->agreement($workspace, ['starts_on' => '2026-01-01'], $other);

        $this->entry($workspace, $company, $project);
    }
}
