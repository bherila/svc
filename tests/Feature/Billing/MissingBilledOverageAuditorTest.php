<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\MissingBilledOverageAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The audit behind #144.
 *
 * A charged invoice with a null `hours_billed_at_rate` contributes nothing to
 * the three sums that decide how much overage an agreement has already been
 * charged, so its hours can be sold again. This counts how many such rows
 * exist and - the number that matters - how many agreements' sums they corrupt.
 *
 * Every stage of the funnel reports a different number on purpose. Where
 * consecutive stages agree, a funnel that skipped one reports the same totals
 * and nothing notices.
 */
final class MissingBilledOverageAuditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_stage_of_the_funnel_narrows(): void
    {
        $workspace = $this->workspace('funnel');
        $company = $this->company($workspace, 'funnel');
        $first = $this->agreement($workspace, $company);
        $second = $this->agreement($workspace, $company);

        // Counted all the way through, on two different agreements - so the
        // agreement count is not simply the invoice count.
        $this->invoice($workspace, $company, $first, ['status' => 'issued', 'hours_billed_at_rate' => null]);
        $this->invoice($workspace, $company, $first, ['status' => 'paid', 'hours_billed_at_rate' => null]);
        $this->invoice($workspace, $company, $second, ['status' => 'issued', 'hours_billed_at_rate' => null]);

        // Dropped, one per stage.
        $this->invoice($workspace, $company, $first, ['status' => 'draft', 'hours_billed_at_rate' => null]);
        $this->invoice($workspace, $company, null, ['status' => 'issued', 'hours_billed_at_rate' => null]);
        $this->invoice($workspace, $company, $first, ['status' => 'issued', 'hours_billed_at_rate' => '0.0000']);

        $counts = app(MissingBilledOverageAuditor::class)->count($workspace);

        $this->assertSame(6, $counts->invoices);
        $this->assertSame(5, $counts->withoutABilledOverage);
        $this->assertSame(4, $counts->chargedOfThose);
        $this->assertSame(3, $counts->onAnAgreementOfThose);
        $this->assertSame(2, $counts->agreementsAffected);
    }

    /**
     * Zero is a figure; null is the absence of one.
     *
     * An invoice that charged no overage says so with a zero, and contributes
     * a zero to the sum - correctly. Counting it here would report a defect
     * where the data is exactly right, which is how an audit stops being
     * believed.
     */
    public function test_a_zero_overage_is_not_a_missing_one(): void
    {
        $workspace = $this->workspace('zero');
        $company = $this->company($workspace, 'zero');
        $agreement = $this->agreement($workspace, $company);

        $this->invoice($workspace, $company, $agreement, ['status' => 'issued', 'hours_billed_at_rate' => '0.0000']);

        $counts = app(MissingBilledOverageAuditor::class)->count($workspace);

        $this->assertSame(0, $counts->withoutABilledOverage);
        $this->assertSame(0, $counts->agreementsAffected);
    }

    /**
     * The console runs unscoped; anything tenant-facing must not.
     *
     * Asserted with a clean workspace beside a broken neighbour, because a
     * leaking scope would still pass a two-affected-workspaces test by
     * coincidence.
     */
    public function test_a_scoped_audit_sees_only_its_own_workspace(): void
    {
        $clean = $this->workspace('clean');
        $broken = $this->workspace('broken');
        $company = $this->company($broken, 'broken');
        $agreement = $this->agreement($broken, $company);
        $this->invoice($broken, $company, $agreement, ['status' => 'issued', 'hours_billed_at_rate' => null]);

        $auditor = app(MissingBilledOverageAuditor::class);

        $this->assertSame(1, $auditor->count($broken)->agreementsAffected);
        $this->assertSame(0, $auditor->count($clean)->agreementsAffected);
        $this->assertSame(0, $auditor->count($clean)->invoices);
        $this->assertSame(1, $auditor->count()->agreementsAffected);
    }

    private function workspace(string $slug): Workspace
    {
        return Workspace::query()->create([
            'name' => ucfirst($slug).' Workspace',
            'slug' => $slug.'-overage-workspace',
        ]);
    }

    private function company(Workspace $workspace, string $slug): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => ucfirst($slug).' Client',
            'slug' => $slug.'-overage-client',
        ]);
    }

    private function agreement(Workspace $workspace, ClientCompany $company): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function invoice(
        Workspace $workspace,
        ClientCompany $company,
        ?ClientAgreement $agreement,
        array $overrides = [],
    ): ClientInvoice {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_agreement_id' => $agreement?->id,
            'invoice_number' => strtoupper($workspace->slug).'-'.uniqid(),
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        // `forceFill`, because the audit's subject is columns a normal create
        // would not let a fixture set - a charged status, and an absent figure.
        $invoice->forceFill($overrides)->save();

        return $invoice;
    }
}
