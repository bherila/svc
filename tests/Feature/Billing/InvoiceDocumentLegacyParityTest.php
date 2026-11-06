<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Services\Billing\InvoiceDocumentService;
use App\Support\Billing\InvoiceLineDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsGoldenInvoices;
use Tests\Support\Billing\BladeInvoiceDocumentFacts;
use Tests\Support\Billing\LegacyInvoiceHtml;
use Tests\TestCase;

/**
 * The new invoice document prints every fact the old template printed, with the
 * same values.
 *
 * Each invoice below is rendered through the frozen old template
 * ({@see LegacyInvoiceHtml}) and through the `InvoiceDocument` document, for both
 * audiences, and the facts read from the two are compared as a whole. The
 * golden test classes hold the new document to exact figures; this holds it to
 * the old document's, so nothing the old one said can quietly go missing. The
 * new document may say more (a pay link, a void notice, the issuer), and the
 * facts it adds are not part of the comparison.
 */
class InvoiceDocumentLegacyParityTest extends TestCase
{
    use BuildsGoldenInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoldenTenant();
    }

    /** @return iterable<string, array{string, string}> */
    public static function cases(): iterable
    {
        foreach (['simple', 'retainer_with_rollover_and_overage', 'correction', 'voided', 'reissued_with_deferred'] as $case) {
            foreach ([InvoiceLineDetail::OPERATOR, InvoiceLineDetail::CLIENT] as $audience) {
                yield $case.' / '.$audience => [$case, $audience];
            }
        }
    }

    #[DataProvider('cases')]
    public function test_the_new_document_prints_every_fact_the_old_one_printed(string $case, string $audience): void
    {
        $invoice = $this->{'build'.str_replace('_', '', ucwords($case, '_'))}();

        $old = (new BladeInvoiceDocumentFacts(new LegacyInvoiceHtml))->extract($invoice, $audience);
        $new = (new BladeInvoiceDocumentFacts(app(InvoiceDocumentService::class)))->extract($invoice, $audience);

        $this->assertNotSame([], $old['lines'], 'The fixture must have lines to compare.');
        if (in_array($case, ['retainer_with_rollover_and_overage', 'correction'], true)) {
            $this->assertNotNull($old['hours_statement'], 'The fixture must carry an hours statement to compare.');
        }
        if ($case === 'reissued_with_deferred') {
            $this->assertNotNull($old['still_deferred'], 'The fixture must carry deferred work to compare.');
        }
        if ($case === 'retainer_with_rollover_and_overage') {
            $this->assertNotNull($old['appendix'], 'The fixture must carry an appendix to compare.');
        }
        // Lines are grouped by section in the new document, so the same lines may
        // print in another order; every one of them must still print, unchanged.
        $byDescription = static fn (array $a, array $b): int => $a['description'] <=> $b['description'];
        usort($old['lines'], $byDescription);
        usort($new['lines'], $byDescription);
        $this->assertSame($old, $new);
    }

    private function buildSimple(): ClientInvoice
    {
        $invoice = $this->goldenInvoice([
            'subtotal_amount' => 160000, 'tax_amount' => 10000, 'total_amount' => 170000, 'balance_amount' => 170000,
        ]);
        $this->goldenLine($invoice, ['description' => 'Synthetic retainer', 'sort_order' => 1]);
        $this->goldenLine($invoice, ['type' => 'recurring_item', 'description' => 'Synthetic hosting', 'tax_amount' => 10000, 'total_amount' => 10000, 'unit_amount' => 10000, 'sort_order' => 2]);

        return $invoice;
    }

    private function buildRetainerWithRolloverAndOverage(): ClientInvoice
    {
        $invoice = $this->goldenInvoice([
            'subtotal_amount' => 187500, 'tax_amount' => 5000, 'total_amount' => 192500, 'paid_amount' => 50000, 'balance_amount' => 142500,
            'status' => 'partially_paid',
            'hours_statement' => $this->goldenStatement([
                'openingRetainerHours' => 10.0, 'openingRolloverHours' => 3.5, 'openingDeficitHours' => 1.0, 'openingExpiredHours' => 2.0,
                'ordinaryHours' => 14.5, 'ordinaryAppliedToWorkPool' => 12.5, 'ordinaryAppliedToNextRetainer' => 0.5, 'ordinaryBilledAtRate' => 1.5,
                'subcontractorHours' => 2.0, 'deferredAppliedHours' => 1.25, 'deferredAppliedEntries' => 2, 'recarriedSettledHours' => 0.5,
                'deferredBilledOnTerminationHours' => 0.75, 'catchUpBilledHours' => 1.0, 'minimumAvailabilityHours' => 2.0,
                'minimumAvailabilityThresholdHours' => 8.0, 'interimBilledHours' => 0.25, 'rolledForwardHours' => 1.5, 'expiringHours' => 0.5,
                'deficitCarriedForwardHours' => 0.0, 'deferredBacklogHours' => 3.5, 'deferredBacklogEntries' => 2, 'recarriedRemainingHours' => 0.25,
                'nextRetainerHours' => 10.0, 'expiredWithinPeriodHours' => 1.0,
            ])->toArray(),
        ]);
        $retainer = $this->goldenLine($invoice, ['description' => 'Synthetic retainer for September', 'sort_order' => 1]);
        $hours = $this->goldenLine($invoice, ['type' => 'additional_hours', 'description' => 'Additional hours (2.50 hrs)', 'quantity' => '2.5', 'unit_amount' => 15000, 'tax_amount' => 5000, 'total_amount' => 37500, 'sort_order' => 2]);
        $sub = $this->goldenLine($invoice, ['type' => 'subcontractor', 'description' => 'Synthetic partner hours', 'sort_order' => 3]);
        $expense = $this->goldenLine($invoice, ['type' => 'expense', 'description' => 'Synthetic staging database', 'sort_order' => 4]);
        $this->goldenLine($invoice, ['type' => 'carried_deferred_applied', 'description' => 'Deferred work applied (1.25 hrs)', 'sort_order' => 5]);
        $this->goldenLine($invoice, ['type' => 'credit', 'description' => 'Synthetic credit', 'unit_amount' => -5000, 'total_amount' => -5000, 'sort_order' => 1]);
        $this->goldenAttach($hours, $this->goldenEntry('2026-08-03', 90, ['description' => 'Synthetic internal note']));
        $this->goldenAttach($hours, $this->goldenEntry('2026-08-04', 60, ['is_visible_to_client' => true, 'client_visible_description' => 'Reconciled the purchase order']));
        $this->goldenAttach($sub, $this->goldenEntry('2026-08-05', 120, ['is_visible_to_client' => true, 'client_visible_description' => '  ']));
        unset($retainer, $expense);

        return $invoice;
    }

    private function buildCorrection(): ClientInvoice
    {
        $invoice = $this->goldenInvoice([
            'hours_statement' => $this->goldenStatement([
                'retainerSoldBy' => 'INV-SYN-EARLIER-0001', 'poolRemainingHours' => 4.5, 'availableBeforeHours' => 9.0,
                'ordinaryHours' => 6.0, 'ordinaryAppliedToWorkPool' => 4.5, 'ordinaryBilledAtRate' => 1.5,
            ])->toArray(),
        ]);
        $this->goldenLine($invoice, ['type' => 'adjustment', 'description' => 'Synthetic correction', 'sort_order' => 1]);

        return $invoice;
    }

    private function buildVoided(): ClientInvoice
    {
        $invoice = $this->goldenInvoice(['status' => 'void', 'voided_at' => '2026-08-20 10:00:00', 'void_reason' => 'Synthetic internal reason']);
        $this->goldenLine($invoice, ['description' => 'Synthetic voided retainer']);

        return $invoice;
    }

    private function buildReissuedWithDeferred(): ClientInvoice
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'title' => 'Synthetic parity retainer', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'billing_cadence' => 'monthly', 'retainer_minutes' => 600, 'retainer_amount' => 100000, 'hourly_rate_amount' => 10000,
            'show_deferred_backlog_to_client' => true,
        ]);
        $invoice = $this->goldenInvoice([
            'reissued_at' => '2026-08-20 09:30:00',
            'client_agreement_id' => $agreement->id,
            'deferred_work_statement' => ['version' => 2, 'entries' => [
                ['id' => 12, 'workedOn' => '2026-06-15', 'minutes' => 120, 'description' => null, 'projectId' => $this->project->id],
                ['id' => 11, 'workedOn' => '2026-06-01', 'minutes' => 90, 'description' => 'Reviewed the roadmap', 'projectId' => $this->project->id],
            ]],
        ]);
        $this->goldenLine($invoice, ['description' => 'Synthetic reissued retainer']);

        return $invoice;
    }
}
