<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\FirstCycleChargeAuditor;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Support\Billing\FirstCycleChargeFinding;
use App\Support\Billing\FirstCycleChargeOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsServicePackages;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * The read-only listing of agreements whose first billing periods charged a
 * recurring item an extra time (#685, #695): every agreement of the shape,
 * what its first periods' invoices hold, and nothing read across a workspace
 * or written anywhere.
 *
 * The invoices are written as rows, as the engine wrote them before #685: it
 * no longer can.
 */
final class AuditFirstCycleChargesCommandTest extends TestCase
{
    use BuildsServicePackages;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    #[Requirement('INV-1')]
    public function test_each_agreement_of_the_shape_is_listed_with_what_its_first_invoice_charged(): void
    {
        $workspace = $this->workspace('one');
        $issued = $this->agreement($workspace, 'quarterly', '2026-01-09');
        $item = $this->item($issued);
        $this->firstInvoice($issued, 'paid', [[$item, '2026-01-09', 12000], [$item, '2026-04-01', 12000]]);
        $draft = $this->agreement($workspace, 'annual', '2026-01-31');
        $this->firstInvoice($draft, 'draft', [[$annual = $this->item($draft, 'annual', 48000), '2026-01-31', 48000], [$annual, '2027-01-01', 48000]]);
        $once = $this->agreement($workspace, 'semi_annual', '2026-03-15');
        $this->firstInvoice($once, 'issued', [[$this->item($once, 'semi_annual'), '2026-03-15', 12000]]);
        $waiting = $this->agreement($workspace, 'quarterly', '2026-02-09');
        $this->item($waiting);
        $voided = $this->agreement($workspace, 'quarterly', '2026-02-10');
        $this->firstInvoice($voided, 'void', [[$voidedItem = $this->item($voided), '2026-02-10', 12000], [$voidedItem, '2026-04-01', 12000]]);

        // An item on another cadence (#695): a monthly one four times in a
        // quarter from the 9th, an annual one again on 1 January in its
        // fourth quarter, a monthly one charged as the engine now does.
        $monthly = $this->agreement($workspace, 'quarterly', '2026-01-09');
        $hosting = $this->item($monthly, 'monthly', 4500);
        $this->firstInvoice($monthly, 'issued', array_map(static fn (string $date): array => [$hosting, $date, 4500], ['2026-01-09', '2026-02-01', '2026-03-01', '2026-04-01']));
        $licence = $this->agreement($workspace, 'quarterly', '2026-01-09');
        $this->firstInvoice($licence, 'paid', [[$annual = $this->item($licence, 'annual', 48000), '2026-01-09', 48000]]);
        $fourth = $this->firstInvoice($licence, 'issued', [[$annual, '2027-01-01', 48000]], cycleStart: '2026-10-09');
        $current = $this->agreement($workspace, 'semi_annual', '2026-01-31');
        $now = $this->item($current, 'monthly', 4500);
        $this->firstInvoice($current, 'draft', array_map(static fn (string $date): array => [$now, $date, 4500], ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30']));

        // Not the shape: started on the 1st, monthly, a one-time item, or one
        // carrying an anchor (a move's successor, or a day set by hand).
        $this->item($this->agreement($workspace, 'quarterly', '2026-01-01'));
        $this->item($this->agreement($workspace, 'quarterly', '2026-01-01'), 'monthly');
        $this->item($this->agreement($workspace, 'monthly', '2026-01-09'), 'monthly');
        $this->item($this->agreement($workspace, 'quarterly', '2026-01-09'), 'one_time');
        $this->item($this->agreement($workspace, 'quarterly', '2026-01-09'), anchorDay: 1);
        $this->item($this->agreement($workspace, 'quarterly', '2026-01-09'), 'monthly', anchorDay: 1);

        $report = $this->report(['--list' => true]);

        $this->assertSame([
            [$issued->public_id, 'issued_with_duplicate', 'paid', 1, 12000],
            [$draft->public_id, 'draft_with_duplicate', 'draft', 1, 48000],
            [$once->public_id, 'charged_once', 'issued', 0, 0],
            [$waiting->public_id, 'not_invoiced', null, 0, 0],
            [$voided->public_id, 'not_invoiced', null, 0, 0],
            [$monthly->public_id, 'issued_with_duplicate', 'issued', 1, 4500],
            [$licence->public_id, 'issued_with_duplicate', 'issued', 1, 48000],
            [$current->public_id, 'charged_once', 'draft', 0, 0],
        ], array_map(static fn (array $row): array => [$row['agreement'], $row['outcome'], $row['invoice_status'], $row['duplicate_lines'], $row['overcharge_minor']], $report['agreements']));
        $this->assertSame(['workspace' => $workspace->public_id, 'cadence' => 'quarterly', 'starts_on' => '2026-01-09', 'package' => false, 'items' => 1], array_intersect_key($report['agreements'][0], array_flip(['workspace', 'cadence', 'starts_on', 'package', 'items'])));
        // The licence's extra charge is named by the invoice that holds it.
        $this->assertSame($fourth->public_id, $report['agreements'][6]['invoice']);
        $this->assertSame([
            'agreements' => 8,
            'packages' => 0,
            'outcomes' => ['issued_with_duplicate' => 3, 'draft_with_duplicate' => 1, 'charged_once' => 2, 'not_invoiced' => 2],
            'issued_overcharge_minor_by_currency' => ['USD' => 64500],
        ], $report['summary']);

        // Without --list, figures only: safe to paste.
        $this->assertSame(['summary'], array_keys($this->report()));
    }

    /**
     * A sign-up made today is of the shape too, and is billed once: its
     * agreement is listed as a package's, first not invoiced, then charged once.
     */
    #[Requirement('INV-1', 'ACC-3')]
    public function test_a_package_sign_up_is_listed_as_a_packages_and_charged_once(): void
    {
        config(['svc.service_packages.enabled' => true]);
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2027-01-09 10:00:00', 'UTC'));
        [$workspace, $owner] = $this->packageWorkspace();
        $workspace->forceFill(['timezone' => 'UTC'])->save();
        $package = $this->publishedPackage($workspace, $owner, $this->hostingPackageInput([
            'billing_cadence' => 'quarterly', 'invoice_issuing' => 'draft',
            'components' => [
                ['kind' => 'recurring', 'description' => 'Synthetic hosting', 'cadence' => 'quarterly', 'amount' => 12000],
                // On another cadence (#695), so charged three times in the quarter.
                ['kind' => 'recurring', 'description' => 'Synthetic monitoring', 'cadence' => 'monthly', 'amount' => 900],
            ],
        ]));
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic package client', 'slug' => 'synthetic-package-client']);
        $contact = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $contact->id, 'role' => 'member', 'access_scope' => 'company']);
        $enrollments = app(PackageEnrollmentWorkflow::class);
        $enrollment = $enrollments->accept($contact, $company, $offer = $enrollments->addFromPortal($contact, $company, $package), $this->signatureFor($offer));
        $agreement = ClientAgreement::query()->whereKey($enrollment->client_agreement_id)->sole();

        $findings = app(FirstCycleChargeAuditor::class)->findings($workspace);
        $this->assertSame([[$agreement->public_id, true, 2, FirstCycleChargeOutcome::NotInvoiced]], array_map(static fn (FirstCycleChargeFinding $finding): array => [$finding->agreement, $finding->package, $finding->items, $finding->outcome], $findings));

        app(ClientInvoicingService::class)->generateAllInvoices($company->refresh());
        $findings = app(FirstCycleChargeAuditor::class)->findings($workspace);
        $this->assertSame([[FirstCycleChargeOutcome::ChargedOnce, 'draft', 0]], array_map(static fn (FirstCycleChargeFinding $finding): array => [$finding->outcome, $finding->invoiceStatus, $finding->duplicateLines], $findings));
    }

    #[Requirement('INV-1')]
    public function test_a_workspace_reads_only_its_own_agreements_and_rows(): void
    {
        $mine = $this->workspace('mine');
        $theirs = $this->workspace('theirs');
        $agreement = $this->agreement($mine, 'quarterly', '2026-01-09');
        $item = $this->item($agreement);
        $this->firstInvoice($agreement, 'issued', [[$item, '2026-01-09', 12000]]);
        $other = $this->agreement($theirs, 'quarterly', '2026-01-09');
        $this->firstInvoice($other, 'issued', [[$theirItem = $this->item($other), '2026-01-09', 12000], [$theirItem, '2026-04-01', 12000]]);
        // Rows of the other workspace stamped with this agreement's ids, which
        // the tenant keys now refuse but older data may hold, are never read.
        $this->writingLegacyCrossTenantRows(function () use ($agreement, $item, $theirs): void {
            $foreign = $this->firstInvoice($agreement, 'issued', [], $theirs);
            $this->line($foreign, $item, '2026-04-01', 99900, $theirs);
            $this->line(ClientInvoice::query()->where('client_agreement_id', $agreement->id)->where('workspace_id', $agreement->workspace_id)->sole(), $item, '2026-04-01', 99900, $theirs);
            ClientAgreementRecurringItem::query()->create(['workspace_id' => $theirs->id, 'client_agreement_id' => $agreement->id, 'description' => 'Synthetic foreign item', 'cadence' => 'quarterly', 'amount' => 100, 'currency' => 'USD', 'is_active' => true]);
        });

        $scoped = $this->report(['--list' => true, '--workspace' => $mine->public_id]);
        $this->assertSame([[$mine->public_id, $agreement->public_id, 'charged_once', 1]], array_map(static fn (array $row): array => [$row['workspace'], $row['agreement'], $row['outcome'], $row['items']], $scoped['agreements']));
        $this->assertSame([[$theirs->public_id, 'issued_with_duplicate']], array_map(static fn (array $row): array => [$row['workspace'], $row['outcome']], $this->report(['--list' => true, '--workspace' => $theirs->public_id])['agreements']));
        $this->assertCount(2, $this->report(['--list' => true])['agreements']);
    }

    public function test_a_bare_or_unknown_workspace_is_refused_rather_than_read_as_every_tenant(): void
    {
        $this->assertSame(2, Artisan::call('svc:billing:audit-first-cycle-charges', ['--workspace' => '']));
        $this->assertSame(2, Artisan::call('svc:billing:audit-first-cycle-charges', ['--workspace' => (string) Str::uuid()]));
    }

    public function test_it_writes_nothing(): void
    {
        $workspace = $this->workspace('readonly');
        $agreement = $this->agreement($workspace, 'quarterly', '2026-01-09');
        $item = $this->item($agreement);
        $invoice = $this->firstInvoice($agreement, 'issued', [[$item, '2026-01-09', 12000], [$item, '2026-04-01', 12000]]);
        $before = [ClientInvoice::query()->whereKey($invoice->id)->sole()->getAttributes(), ClientInvoiceLine::query()->orderBy('id')->get()->toArray(), ClientAgreementRecurringItem::query()->orderBy('id')->get()->toArray()];

        $this->assertSame(0, Artisan::call('svc:billing:audit-first-cycle-charges'));
        $this->assertStringContainsString('1 agreement(s) were sent an invoice charging a recurring item an extra time in its first period', Artisan::output());

        $this->assertSame($before, [ClientInvoice::query()->whereKey($invoice->id)->sole()->getAttributes(), ClientInvoiceLine::query()->orderBy('id')->get()->toArray(), ClientAgreementRecurringItem::query()->orderBy('id')->get()->toArray()]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function report(array $options = []): array
    {
        $this->assertSame(0, Artisan::call('svc:billing:audit-first-cycle-charges', ['--format' => 'json', ...$options]));

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function workspace(string $slug): Workspace
    {
        return Workspace::query()->create(['name' => 'Synthetic '.$slug, 'slug' => 'synthetic-first-cycle-'.$slug, 'default_currency' => 'USD']);
    }

    private function agreement(Workspace $workspace, string $cadence, string $startsOn): ClientAgreement
    {
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client '.Str::random(6), 'slug' => 'synthetic-client-'.Str::uuid()]);

        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic service',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => $startsOn, 'billing_cadence' => $cadence,
        ]);
    }

    private function item(ClientAgreement $agreement, string $cadence = 'quarterly', int $amount = 12000, ?int $anchorDay = null): ClientAgreementRecurringItem
    {
        return ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $agreement->workspace_id, 'client_agreement_id' => $agreement->id, 'description' => 'Synthetic hosting',
            'cadence' => $cadence, 'amount' => $amount, 'currency' => 'USD', 'is_active' => true, 'anchor_day' => $anchorDay,
        ]);
    }

    /** @param list<array{ClientAgreementRecurringItem, string, int}> $lines */
    private function firstInvoice(ClientAgreement $agreement, string $status, array $lines, ?Workspace $in = null, ?string $cycleStart = null): ClientInvoice
    {
        $opens = $cycleStart === null ? $agreement->starts_on : CarbonImmutable::parse($cycleStart);
        $workspaceId = $in === null ? $agreement->workspace_id : $in->id;
        $companyId = $in === null ? $agreement->client_company_id : ClientCompany::query()->create(['workspace_id' => $in->id, 'name' => 'Synthetic foreign client', 'slug' => 'synthetic-foreign-'.Str::uuid()])->id;
        $total = array_sum(array_column($lines, 2));
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspaceId, 'client_company_id' => $companyId, 'client_agreement_id' => $agreement->id,
            'invoice_number' => 'SYNTH-'.Str::upper(Str::random(8)), 'invoice_kind' => 'cadence_period', 'status' => $status, 'currency' => 'USD',
            'subtotal_amount' => $total, 'tax_amount' => 0, 'total_amount' => $total, 'balance_amount' => $status === 'paid' ? 0 : $total,
            'cycle_start' => $opens->toDateString(), 'cycle_end' => $opens->addMonths(3)->subDay()->toDateString(),
            'issue_date' => $opens->toDateString(),
        ]);
        foreach ($lines as [$item, $date, $amount]) {
            $this->line($invoice, $item, $date, $amount);
        }

        return $invoice;
    }

    private function line(ClientInvoice $invoice, ClientAgreementRecurringItem $item, string $date, int $amount, ?Workspace $in = null): void
    {
        ClientInvoiceLine::query()->create([
            'workspace_id' => $in === null ? $invoice->workspace_id : $in->id, 'client_invoice_id' => $invoice->id, 'client_agreement_id' => $item->client_agreement_id,
            'client_agreement_recurring_item_id' => $item->id, 'description' => 'Synthetic hosting', 'type' => 'recurring_item',
            'quantity' => '1.000', 'unit_amount' => $amount, 'tax_amount' => 0, 'total_amount' => $amount, 'line_date' => $date, 'sort_order' => 1,
        ]);
    }
}
