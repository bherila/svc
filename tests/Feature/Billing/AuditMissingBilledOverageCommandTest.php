<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * What the command prints, and what it must never print.
 *
 * `MissingBilledOverageAuditorTest` covers the arithmetic. This covers the two
 * things only the command can get wrong: the `--format=json` shape, which
 * something downstream will parse, and the promise that the output is safe to
 * paste into a public issue.
 *
 * The safety assertion is the important one. It is stated as an absence, and an
 * absence is exactly the kind of claim that passes by accident, so the fixture
 * is named with strings that appear nowhere else in the codebase and a control
 * string is asserted present alongside them being missing - proving the output
 * was captured at all.
 */
final class AuditMissingBilledOverageCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_a_format_it_cannot_produce(): void
    {
        $this->artisan('svc:billing:audit-missing-billed-overage', ['--format' => 'yaml'])
            ->expectsOutputToContain('The --format option must be text or json.')
            ->assertExitCode(2);
    }

    /**
     * The json shape, key by key.
     *
     * Asserted as an exact key set rather than a subset: a consumer reading
     * this parses a fixed contract, and a key silently disappearing is the
     * failure that would otherwise reach them instead of CI.
     */
    public function test_the_json_contract_is_the_full_set_of_counts(): void
    {
        $this->affectedInvoice();

        Artisan::call('svc:billing:audit-missing-billed-overage', ['--format' => 'json']);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'invoices',
            'without_a_billed_overage',
            'charged_of_those',
            'on_an_agreement_of_those',
            'agreements_affected',
        ], array_keys($payload['summary']));

        // Every value, not just one. The fixture makes the stages differ -
        // 4, 3, 2, 1, 1 - so a mapping that named the right keys against the
        // wrong properties changes a number here. With one invoice they would
        // all read 1 and any permutation of them would pass.
        $this->assertSame([
            'invoices' => 4,
            'without_a_billed_overage' => 3,
            'charged_of_those' => 2,
            'on_an_agreement_of_those' => 1,
            'agreements_affected' => 1,
        ], $payload['summary']);
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
        $this->affectedInvoice();

        foreach (['text', 'json'] as $format) {
            Artisan::call('svc:billing:audit-missing-billed-overage', ['--format' => $format]);
            $output = Artisan::output();

            // The control: proves the output was captured, so the absences
            // below are absences from something rather than from an empty
            // string.
            $this->assertStringContainsString('1', $output);

            foreach ([
                'Anaphora Freight',       // company name
                'anaphora-freight',       // company slug
                'Tessellate Retainer',    // agreement title
                'ANAPHORA-INV-0001',      // invoice number
                'anaphora-workspace',     // workspace slug
            ] as $identifier) {
                $this->assertStringNotContainsString($identifier, $output, "$format output named $identifier");
            }
        }
    }

    /**
     * The warning fires on the affected population and the clean line does not.
     *
     * Both directions, because a command that always warns and a command that
     * never warns are equally useless and only one of them looks broken.
     *
     * The warning's exact hedge is asserted too. It says a sum *may* read short,
     * because a null proves the contribution is unknown rather than that the
     * invoice carried overage - and an audit that overstates its findings is one
     * nobody believes when it next reports zero.
     */
    public function test_it_warns_only_when_an_agreement_is_affected(): void
    {
        $this->assertStringContainsString('#144 is latent', $this->text());

        $this->affectedInvoice();

        $affected = $this->text();
        $this->assertStringNotContainsString('#144 is latent', $affected);
        $this->assertStringContainsString('may have an already-billed sum that reads short', $affected);
        $this->assertStringContainsString('A null is not a quantity', $affected);
    }

    /**
     * The text output with its console line-wrapping flattened.
     *
     * The warning is a sentence long and the component wraps it to the
     * terminal, so a phrase asserted verbatim can straddle a newline and fail
     * for reasons that have nothing to do with what the command said. Collapsing
     * runs of whitespace asserts the wording rather than the layout.
     */
    private function text(): string
    {
        Artisan::call('svc:billing:audit-missing-billed-overage');

        return (string) preg_replace('/\s+/', ' ', Artisan::output());
    }

    /**
     * Four invoices, one per stage of the funnel, so no two stages report the
     * same number.
     *
     * Only the first is affected: charged, on an agreement in its own
     * workspace, and carrying no billed-overage figure. The other three each
     * fall out at a different stage, which is what makes the counts distinct.
     */
    private function affectedInvoice(): void
    {
        $workspace = Workspace::query()->create([
            'name' => 'Anaphora Workspace',
            'slug' => 'anaphora-workspace',
        ]);

        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Anaphora Freight',
            'slug' => 'anaphora-freight',
        ]);

        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Tessellate Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
        ]);

        $invoice = function (?ClientAgreement $on, array $overrides) use ($workspace, $company): void {
            ClientInvoice::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'client_agreement_id' => $on?->id,
                'invoice_number' => 'ANAPHORA-INV-'.str_pad((string) (ClientInvoice::query()->count() + 1), 4, '0', STR_PAD_LEFT),
                'status' => 'draft',
                'currency' => 'USD',
                'subtotal_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
            ])->forceFill($overrides)->save();
        };

        // Counted to the end.
        $invoice($agreement, ['status' => 'issued', 'hours_billed_at_rate' => null]);

        // Dropped for naming no agreement.
        $invoice(null, ['status' => 'issued', 'hours_billed_at_rate' => null]);

        // Dropped for not being charged.
        $invoice($agreement, ['status' => 'draft', 'hours_billed_at_rate' => null]);

        // Dropped at the first stage: it carries a figure.
        $invoice($agreement, ['status' => 'issued', 'hours_billed_at_rate' => '5.0000']);
    }
}
