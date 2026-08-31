<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\UnplaceableInvoiceAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The audit's scope, which the console command never exercises.
 *
 * `AuditUnplaceableInvoicesCommandTest` covers what counts as affected; this
 * covers *whose*. The console runs unscoped on purpose - an operator sizing a
 * migration needs every workspace at once - so nothing there would notice if
 * the workspace parameter silently did nothing, and a tenant-facing surface
 * built on top would report one client's data-quality problem to another.
 *
 * That makes this the tenancy boundary for the audit, and it is asserted the
 * way the isolation harness asserts every other read surface: with a second
 * workspace that must not appear, rather than a single-tenant happy path.
 */
final class UnplaceableInvoiceAuditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unscoped_audit_counts_every_workspace(): void
    {
        $this->affectedInvoiceIn('first');
        $this->affectedInvoiceIn('second');

        $counts = app(UnplaceableInvoiceAuditor::class)->count();

        $this->assertSame(2, $counts->invoices);
        $this->assertSame(2, $counts->affected);
        $this->assertSame(11.0, $counts->overageHoursAtStake);
    }

    public function test_a_scoped_audit_sees_only_its_own_workspace(): void
    {
        $mine = $this->affectedInvoiceIn('first');
        $this->affectedInvoiceIn('second');

        $counts = app(UnplaceableInvoiceAuditor::class)->count($mine);

        $this->assertSame(1, $counts->invoices);
        $this->assertSame(1, $counts->affected);
        $this->assertSame(5.5, $counts->overageHoursAtStake);
    }

    /**
     * A neighbour's broken rows must not raise this workspace's count, even
     * when this workspace is clean. Asserted separately because a scope that
     * leaked would still pass the test above by coincidence: both workspaces
     * are affected there, so a wrong number is a plausible number.
     */
    public function test_a_clean_workspace_reports_nothing_when_its_neighbour_is_broken(): void
    {
        $clean = $this->workspace('clean');
        $this->affectedInvoiceIn('broken');

        $counts = app(UnplaceableInvoiceAuditor::class)->count($clean);

        $this->assertSame(0, $counts->invoices);
        $this->assertSame(0, $counts->affected);
        $this->assertSame(0.0, $counts->overageHoursAtStake);
        $this->assertSame(0, $counts->liveWithoutACycle);
    }

    private function workspace(string $slug): Workspace
    {
        return Workspace::query()->create([
            'name' => ucfirst($slug).' Workspace',
            'slug' => $slug.'-workspace',
        ]);
    }

    /**
     * One charged, agreement-bound invoice with overage and no service period -
     * the shape the audit is meant to count - in a workspace of its own.
     */
    private function affectedInvoiceIn(string $slug): Workspace
    {
        $workspace = $this->workspace($slug);

        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => ucfirst($slug).' Client',
            'slug' => $slug.'-client',
        ]);

        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
        ]);

        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => strtoupper($slug).'-'.uniqid(),
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        $invoice->forceFill([
            'status' => 'issued',
            'hours_billed_at_rate' => '5.5',
            'service_period_end' => null,
        ])->save();

        return $workspace;
    }
}
