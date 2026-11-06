<?php

namespace Tests\Concerns;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * A synthetic workspace for the revenue reads, as of 2026-11-06. Every name,
 * slug and number is invented; amounts scale with `$scale` so two workspaces
 * built from it are told apart by their figures.
 *
 * - Alpha: a monthly $1,000 retainer from 2026-01-01 with a taxable
 *   quarterly hosting item, invoiced monthly for May-October 2026. Overage
 *   was billed for July, August and October (with tax); October also bills an
 *   expense and spends a credit and is still open, a day overdue. August and
 *   September are paid, August partly refunded. A draft and a void invoice
 *   carry large amounts nothing may count.
 * - Beta: a signed quarterly agreement starting 2027-01-01.
 * - Gamma: a monthly $500 retainer ending 2026-12-15.
 * - Delta: an inactive client with nothing.
 */
trait BuildsRevenueFixtures
{
    /**
     * @return array{workspace: Workspace, owner: User, alpha: ClientCompany, beta: ClientCompany, gamma: ClientCompany, agreement: ClientAgreement, item: ClientAgreementRecurringItem, october: ClientInvoice}
     */
    protected function revenueWorkspace(string $slug, int $scale = 1): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic revenue '.$slug, 'slug' => 'synthetic-revenue-'.$slug, 'default_currency' => 'USD']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $alpha = $this->revenueClient($workspace, $slug, 'Alpha');
        $beta = $this->revenueClient($workspace, $slug, 'Beta');
        $gamma = $this->revenueClient($workspace, $slug, 'Gamma');
        $this->revenueClient($workspace, $slug, 'Delta', active: false);

        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $alpha->id, 'title' => 'Synthetic Alpha retainer', 'status' => 'active',
            'currency' => 'USD', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'retainer_amount' => 100000 * $scale, 'retainer_minutes' => 600,
            'hourly_rate_amount' => 20000, 'signed_at' => '2026-01-01 09:00:00',
        ]);
        $item = ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $workspace->id, 'client_agreement_id' => $agreement->id, 'description' => 'Synthetic hosting', 'cadence' => 'quarterly',
            'anchor_month' => 1, 'anchor_day' => 1, 'quantity' => '1.000', 'amount' => 30000 * $scale, 'currency' => 'USD', 'is_taxable' => true, 'is_active' => true, 'sort_order' => 1,
        ]);
        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $beta->id, 'title' => 'Synthetic Beta quarterly', 'status' => 'active',
            'currency' => 'USD', 'starts_on' => '2027-01-01', 'billing_cadence' => 'quarterly', 'period_retainer_amount' => 300000 * $scale, 'retainer_minutes' => 600,
            'signed_at' => '2026-10-20 09:00:00',
        ]);
        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $gamma->id, 'title' => 'Synthetic Gamma retainer', 'status' => 'active',
            'currency' => 'USD', 'starts_on' => '2026-03-01', 'ends_on' => '2026-12-15', 'billing_cadence' => 'monthly', 'retainer_amount' => 50000 * $scale,
        ]);

        $overage = ['2026-07' => 20000, '2026-08' => 40000, '2026-10' => 60000];
        $invoices = [];
        foreach (['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'] as $month) {
            $start = CarbonImmutable::parse($month.'-01');
            $next = $start->addMonthNoOverflow();
            $lines = [['retainer', 100000 * $scale, 0, $next->toDateString(), null]];
            if (in_array($next->month, [1, 4, 7, 10], true)) {
                $lines[] = ['recurring_item', 30000 * $scale, 3000 * $scale, $next->toDateString(), $item->id];
            }
            if (isset($overage[$month])) {
                $lines[] = ['additional_hours', $overage[$month] * $scale, $overage[$month] * $scale / 10, $start->endOfMonth()->toDateString(), null];
            }
            if ($month === '2026-10') {
                $lines[] = ['expense', 5000 * $scale, 0, '2026-10-20', null];
                $lines[] = ['credit', -2000 * $scale, 0, null, null];
            }
            $invoices[$month] = $this->revenueInvoice($workspace, $alpha, $agreement, $month === '2026-10' ? 'issued' : 'paid', $start, $lines, $next->toDateString());
        }
        $this->revenueInvoice($workspace, $alpha, $agreement, 'draft', CarbonImmutable::parse('2026-11-01'), [['additional_hours', 999999, 0, '2026-11-30', null]], null);
        $this->revenueInvoice($workspace, $alpha, $agreement, 'void', CarbonImmutable::parse('2026-09-01'), [['expense', 888888, 0, '2026-09-10', null]], '2026-10-01');

        $this->revenuePayment($workspace, $invoices['2026-08'], '2026-09-02', (int) $invoices['2026-08']->total_amount, (int) round($invoices['2026-08']->total_amount / 10));
        $this->revenuePayment($workspace, $invoices['2026-09'], '2026-10-03', (int) $invoices['2026-09']->total_amount, 0);

        return ['workspace' => $workspace, 'owner' => $owner, 'alpha' => $alpha, 'beta' => $beta, 'gamma' => $gamma, 'agreement' => $agreement, 'item' => $item, 'october' => $invoices['2026-10']];
    }

    private function revenueClient(Workspace $workspace, string $slug, string $name, bool $active = true): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => 'Synthetic '.$name.' '.$slug, 'slug' => 'synthetic-'.strtolower($name).'-'.$slug, 'is_active' => $active,
        ]);
    }

    /**
     * @param  list<array{string, int, int, string|null, int|null}>  $lines  type, pre-tax amount, tax, line date, recurring item id
     */
    private function revenueInvoice(Workspace $workspace, ClientCompany $company, ClientAgreement $agreement, string $status, CarbonImmutable $month, array $lines, ?string $issueDate): ClientInvoice
    {
        static $number = 0;
        $number++;
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'invoice_number' => 'SYNTHETIC-REV-'.$workspace->id.'-'.$number, 'status' => $status, 'currency' => 'USD', 'invoice_kind' => 'cadence_period',
            'service_period_start' => $month->toDateString(), 'service_period_end' => $month->endOfMonth()->toDateString(),
            'issue_date' => $issueDate, 'due_date' => $issueDate === null ? null : CarbonImmutable::parse($issueDate)->toDateString(),
        ]);
        foreach ($lines as $sort => [$type, $amount, $tax, $date, $itemId]) {
            ClientInvoiceLine::query()->create([
                'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'client_agreement_id' => $agreement->id,
                'client_agreement_recurring_item_id' => $itemId, 'type' => $type, 'description' => 'Synthetic '.$type,
                'quantity' => '1', 'unit_amount' => $amount, 'tax_amount' => $tax, 'total_amount' => $amount + $tax, 'line_date' => $date, 'sort_order' => $sort,
            ]);
        }
        $total = array_sum(array_map(static fn (array $line): int => $line[1] + $line[2], $lines));
        $paid = $status === 'paid' ? $total : 0;
        // Hours charged at the rate, as the generator records them: the capacity ledger refuses an overage line without them.
        $billedHours = array_sum(array_map(static fn (array $line): float => $line[0] === 'additional_hours' ? $line[1] / 20000 : 0.0, $lines));
        $invoice->forceFill(['hours_billed_at_rate' => number_format($billedHours, 4, '.', ''), 'subtotal_amount' => $total - array_sum(array_column($lines, 2)), 'tax_amount' => array_sum(array_column($lines, 2)), 'total_amount' => $total, 'paid_amount' => $paid, 'balance_amount' => $total - $paid])->save();

        return $invoice;
    }

    private function revenuePayment(Workspace $workspace, ClientInvoice $invoice, string $receivedOn, int $amount, int $refunded): void
    {
        ClientInvoicePayment::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'status' => 'succeeded', 'amount' => $amount, 'refunded_amount' => $refunded,
            'currency' => 'USD', 'received_on' => $receivedOn, 'method' => 'bank_transfer', 'reference' => 'synthetic-revenue',
        ]);
    }
}
