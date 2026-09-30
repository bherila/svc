<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoiceKind;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * An interim overage invoice is issued only while tied to its company's agreement (#342).
 *
 * Its claim, the cycle's other claims and the cadence reconciliation are all
 * found by agreement id. An interim naming no agreement, a missing one, or
 * another company's is invisible to the real agreement's cycle, so the closing
 * invoice could bill its hours again. The generator never writes these shapes;
 * imports and hand edits can.
 */
final class InterimAgreementLinkIssueTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Interim link', 'slug' => 'interim-link']);
        $this->company = $this->company('Interim Client');
    }

    #[DataProvider('unplaceableLinks')]
    public function test_an_interim_not_tied_to_its_companys_agreement_is_refused(string $link, string $problem): void
    {
        $draft = $this->interimDraft();
        $agreementId = match ($link) {
            'none' => null,
            'missing' => 999_999,
            'other company' => $this->agreementFor($this->company('Other Client'))->id,
        };
        $draft->forceFill(['client_agreement_id' => $agreementId])->save();

        $message = $this->refusalFor($draft->refresh());

        $this->assertStringContainsString("This interim_overage invoice {$problem}, so it cannot be issued", $message);
        $this->assertStringContainsString('discard this draft', $message);
        // Not the loop a missing row used to answer with: a reload changes nothing.
        $this->assertStringNotContainsString('Reload it and issue it again', $message);

        $fresh = $draft->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->issued_at);
    }

    public static function unplaceableLinks(): iterable
    {
        yield 'no agreement' => ['none', 'names no agreement'];
        yield 'a missing agreement' => ['missing', 'names an agreement that does not exist in this workspace'];
        yield 'another company\'s agreement' => ['other company', 'names an agreement of a different client company'];
    }

    public function test_an_interim_tied_to_its_companys_agreement_still_issues(): void
    {
        $draft = $this->interimDraft();
        $draft->forceFill(['client_agreement_id' => $this->agreementFor($this->company)->id])->save();

        $issued = app(InvoiceLifecycleService::class)->issue($draft->refresh(), $this->workspace);

        $this->assertSame('issued', $issued->status);
    }

    /** The refusal is the transition's, not the row's: a charged one stays idempotent. */
    public function test_an_already_charged_interim_naming_no_agreement_is_still_idempotent(): void
    {
        $invoice = $this->interimDraft();
        $invoice->forceFill(['status' => 'paid'])->save();

        $returned = app(InvoiceLifecycleService::class)->issue($invoice, $this->workspace);

        $this->assertSame('paid', $returned->status);
    }

    /** An incomplete interim keeps the period refusal, which names its own repair. */
    public function test_an_undated_interim_naming_no_agreement_gets_the_period_refusal(): void
    {
        $draft = $this->interimDraft(end: null);

        $message = $this->refusalFor($draft);

        $this->assertStringContainsString('no service period end', $message);
    }

    private function interimDraft(?string $end = '2026-01-31'): ClientInvoice
    {
        $invoice = app(InvoiceLifecycleService::class)->createDraft(
            $this->workspace,
            $this->company,
            [
                'invoice_number' => 'INT-'.uniqid(),
                'currency' => 'USD',
                'service_period_start' => '2026-01-01',
                'service_period_end' => $end,
            ],
            [['type' => 'adjustment', 'description' => 'Overage', 'quantity' => 1, 'unit_amount' => 10000]],
        );
        // `createDraft()` infers the kind from the schedule link and cannot
        // write an interim; imports and hand edits are how this shape arrives.
        $invoice->forceFill(['invoice_kind' => InvoiceKind::InterimOverage->value])->save();

        return $invoice->fresh() ?? $invoice;
    }

    private function company(string $name): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => $name,
            'slug' => str($name)->slug()->value(),
        ]);
    }

    private function agreementFor(ClientCompany $company): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Quarterly agreement',
            'currency' => 'USD',
            'billing_cadence' => 'quarterly',
            'bill_overage_interim' => true,
            'status' => 'active',
            'starts_on' => '2026-01-01',
        ]);
    }

    private function refusalFor(ClientInvoice $invoice): string
    {
        try {
            app(InvoiceLifecycleService::class)->issue($invoice, $this->workspace);
            $this->fail('Invoice '.$invoice->invoice_number.' must not be issued.');
        } catch (DomainException $refusal) {
            return $refusal->getMessage();
        }
    }
}
