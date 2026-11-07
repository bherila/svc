<?php

namespace Tests\Feature\Billing\Autopay;

use App\Mail\InvoiceMail;
use App\Models\ClientAutopayConsent;
use App\Models\ClientCompanyMembership;
use App\Models\InvoiceAutopayAttempt;
use App\Models\User;
use App\Services\Billing\Autopay\AutopayChargeService;
use App\Services\Billing\InvoiceDocumentService;
use App\Support\Billing\Autopay\AutopayAttemptState;
use App\Support\Billing\InvoiceLineDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsAutopay;
use Tests\TestCase;

/** The client's side of autopay (#636): the Billing page, consent, withdrawal, and the invoice screens and documents. */
final class AutopayPortalTest extends TestCase
{
    use BuildsAutopay;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->restoreAutopayStripe();
        parent::tearDown();
    }

    private function wording(string $workspace, string $company, int $limit = 500_000): string
    {
        return 'I authorize '.$workspace.' to charge Visa ending 4242 for '.$company.' invoices on their due dates, up to $'.number_format($limit / 100, 2).' each, until I turn autopay off.';
    }

    public function test_the_billing_page_offers_a_contact_the_urls_and_the_wording_parts(): void
    {
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $this->autopayInvoice($company, balance: 375_000);

        $this->actingAs($contact)->get(route('portal.billing', $company))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/billing')
                ->where('provider_name', $workspace->name)
                ->where('autopay_available', true)
                ->where('autopay.enabled', false)
                ->where('autopay.enable_href', route('portal.autopay.enable', $company, absolute: false))
                ->where('autopay.disable_href', null)
                ->where('autopay.wording', ['provider' => $workspace->name, 'company' => $company->name, 'method' => 'Visa ending 4242', 'currency' => 'USD'])
                ->where('autopay.largest_invoice', 375_000)
                ->where('autopay.suggested_limit', 400_000)
                ->where('payment_methods.0.label', 'Visa ending 4242')
                ->where('payment_methods.0.is_default', true));
    }

    public function test_a_contact_turns_autopay_on_with_the_stored_wording_and_off_again(): void
    {
        [$workspace, , $company, $contact] = $this->autopayFixture();
        Mail::fake();

        $this->actingAs($contact)->post(route('portal.autopay.enable', $company), [
            'limit_amount' => 500_000,
            'wording' => $this->wording($workspace->name, $company->name),
            'authorized' => true,
        ])->assertRedirect(route('portal.billing', $company));

        $consent = ClientAutopayConsent::query()->sole();
        $this->assertSame($this->wording($workspace->name, $company->name), $consent->wording);
        $this->assertSame(['client', 'portal_checkbox', 'Dana Example'], [$consent->actor_role->value, $consent->method->value, $consent->actor_name]);
        $this->assertSame(500_000, $company->fresh()->autopay_limit_amount);
        Mail::assertNothingSent();

        $this->actingAs($contact)->get(route('portal.billing', $company))->assertInertia(fn (Assert $page) => $page
            ->where('autopay.enabled', true)->where('autopay.limit_amount', 500_000)
            ->where('autopay.disable_href', route('portal.autopay.disable', $company, absolute: false))
            ->where('autopay.consents.0.action', 'enabled')->where('result', 'on'));

        $this->actingAs($contact)->delete(route('portal.autopay.disable', $company))->assertRedirect(route('portal.billing', $company));
        $this->assertNull($company->fresh()->autopay_enabled_at);
        $this->assertSame(['disabled', 'client', 'withdrawal'], [ClientAutopayConsent::query()->latest('id')->first()->action->value, ClientAutopayConsent::query()->latest('id')->first()->actor_role->value, ClientAutopayConsent::query()->latest('id')->first()->method->value]);
    }

    public function test_consent_needs_the_ticked_box_and_the_exact_wording(): void
    {
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $good = ['limit_amount' => 500_000, 'wording' => $this->wording($workspace->name, $company->name), 'authorized' => true];

        $this->actingAs($contact)->post(route('portal.autopay.enable', $company), [...$good, 'authorized' => false])->assertSessionHasErrors('authorized');
        $this->actingAs($contact)->post(route('portal.autopay.enable', $company), array_diff_key($good, ['authorized' => 1]))->assertSessionHasErrors('authorized');
        $this->actingAs($contact)->post(route('portal.autopay.enable', $company), [...$good, 'wording' => 'I agree.'])->assertSessionHasErrors('authorized');
        $this->actingAs($contact)->post(route('portal.autopay.enable', $company), [...$good, 'limit_amount' => 900_000])->assertSessionHasErrors('authorized');
        $this->actingAs($contact)->post(route('portal.autopay.enable', $company), [...$good, 'limit_amount' => 0])->assertSessionHasErrors('limit_amount');

        $this->assertNull($company->fresh()->autopay_enabled_at);
        $this->assertSame(0, ClientAutopayConsent::query()->count());
    }

    public function test_staff_previewing_the_portal_and_project_scoped_contacts_cannot_consent(): void
    {
        [$workspace, $owner, $company, $contact] = $this->autopayFixture();
        $scoped = User::factory()->create();
        $company->portalUsers()->attach($scoped->id, ['workspace_id' => $workspace->id]);
        ClientCompanyMembership::query()->where('user_id', $scoped->id)->update(['access_scope' => ClientCompanyMembership::SCOPE_PROJECTS]);
        $body = ['limit_amount' => 500_000, 'wording' => $this->wording($workspace->name, $company->name), 'authorized' => true];

        foreach ([$owner, $scoped] as $viewer) {
            $this->actingAs($viewer)->post(route('portal.autopay.enable', $company), $body)->assertForbidden();
        }
        // The owner can read the page, with no way to consent.
        $this->actingAs($owner)->get(route('portal.billing', $company))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('autopay.enable_href', null)->where('autopay.disable_href', null));

        $this->turnAutopayOn($workspace, $company, $contact);
        $this->actingAs($owner)->delete(route('portal.autopay.disable', $company))->assertForbidden();
        $this->assertNotNull($company->fresh()->autopay_enabled_at);
    }

    public function test_a_stranger_and_a_guest_are_turned_away(): void
    {
        [, , $company] = $this->autopayFixture();
        $stranger = User::factory()->create();

        // A company the person has no standing with is not found, or refused: never shown.
        foreach ([
            $this->actingAs($stranger)->get(route('portal.billing', $company)),
            $this->actingAs($stranger)->post(route('portal.autopay.enable', $company), ['limit_amount' => 1]),
            $this->actingAs($stranger)->delete(route('portal.autopay.disable', $company)),
        ] as $response) {
            $this->assertContains($response->getStatusCode(), [403, 404]);
        }
        auth()->logout();
        $this->post(route('portal.autopay.enable', $company), ['limit_amount' => 1])->assertRedirect();
    }

    public function test_the_issued_invoice_says_it_will_be_charged_in_the_document_the_pdf_and_the_email(): void
    {
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);
        $invoice = $this->autopayInvoice($company);
        $notice = 'Will be charged to Visa ending 4242 on 2030-12-01.';

        $document = app(InvoiceDocumentService::class)->document($invoice, InvoiceLineDetail::CLIENT)->toArray();
        $this->assertSame($notice, $document['autopay_notice']);
        $this->assertStringContainsString($notice, view('invoices.document', ['document' => app(InvoiceDocumentService::class)->document($invoice, InvoiceLineDetail::CLIENT)])->render());
        $html = (new InvoiceMail($invoice, null, null, 'pdf', 'invoice.pdf', $notice))->render();
        $this->assertStringContainsString($notice, $html);

        $this->actingAs($contact)->get(route('portal.invoice', [$company, $invoice]))->assertInertia(fn (Assert $page) => $page
            ->where('document.autopay_notice', $notice)
            ->where('autopay.state', 'will_charge')->where('autopay.will_charge_on', '2030-12-01')
            ->where('autopay.manage_href', route('portal.billing', $company, absolute: false)));
    }

    public function test_no_notice_when_autopay_is_off_or_the_invoice_is_over_the_limit_or_skipped(): void
    {
        [$workspace, $owner, $company, $contact] = $this->autopayFixture();
        $invoice = $this->autopayInvoice($company);
        $documents = app(InvoiceDocumentService::class);

        $this->assertNull($documents->document($invoice, InvoiceLineDetail::CLIENT)->toArray()['autopay_notice']);
        $this->actingAs($contact)->get(route('portal.invoice', [$company, $invoice]))->assertInertia(fn (Assert $page) => $page->where('autopay', null));

        $this->turnAutopayOn($workspace, $company, $contact, 300_000);
        $this->assertNull($documents->document($invoice->fresh(), InvoiceLineDetail::CLIENT)->toArray()['autopay_notice']);
        $this->actingAs($contact)->get(route('portal.invoice', [$company, $invoice]))->assertInertia(fn (Assert $page) => $page
            ->where('autopay.state', 'over_limit')->where('autopay.limit_amount', 300_000)->where('autopay.will_charge_on', null));

        $this->turnAutopayOn($workspace, $company, $contact, 500_000);
        $this->assertNotNull($documents->document($invoice->fresh(), InvoiceLineDetail::CLIENT)->toArray()['autopay_notice']);
        InvoiceAutopayAttempt::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'client_company_id' => $company->id,
            'state' => AutopayAttemptState::Skipped, 'skipped_by_user_id' => $owner->id, 'skipped_at' => now(), 'claimed_at' => now(),
        ]);
        $this->assertNull($documents->document($invoice->fresh(), InvoiceLineDetail::CLIENT)->toArray()['autopay_notice']);
        // The client is told only that it is not automatic; who skipped it stays with the provider.
        $this->actingAs($contact)->get(route('portal.invoice', [$company, $invoice]))->assertInertia(fn (Assert $page) => $page
            ->where('autopay.state', 'skipped')->missing('autopay.skipped_by_name')->missing('autopay.verdict'));
    }

    public function test_a_failed_charge_is_shown_to_the_client_with_its_reason_and_to_the_operator_with_the_attempt(): void
    {
        $this->travelTo('2030-10-03 18:00:00');
        [$workspace, $owner, $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);
        $invoice = $this->autopayInvoice($company);
        $this->travelTo('2030-12-01 15:00:00');
        $this->fakeAutopayStripe(static fn (): array => [['error' => ['type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card has insufficient funds.']], 402]);
        app(AutopayChargeService::class)->run(50, true);

        $this->actingAs($contact)->get(route('portal.invoice', [$company, $invoice]))->assertInertia(fn (Assert $page) => $page
            ->where('autopay.state', 'failed')->where('autopay.failure_reason', 'Your card has insufficient funds.'));
        $this->actingAs($owner)->get(route('clients.invoice', [$workspace, $company, $invoice]))->assertInertia(fn (Assert $page) => $page
            ->where('autopay.state', 'failed')->where('autopay.attempt.failure_code', 'card_declined')
            ->where('autopay.skip_href', null)
            ->where('autopay.settings_href', route('clients.settings', [$workspace, $company], absolute: false)));
        $this->actingAs($owner)->get(route('clients.settings', [$workspace, $company]))->assertInertia(fn (Assert $page) => $page
            ->where('autopay.enabled', true)
            ->where('autopay.update_href', route('agent-api.v1.clients.autopay.update', [$workspace, $company->public_id], absolute: false))
            ->where('autopay.portal_href', route('portal.billing', $company, absolute: false))
            ->where('autopay.stripe_connected', true));
    }

    public function test_the_operator_invoice_offers_skip_only_while_it_can_be_skipped(): void
    {
        [$workspace, $owner, $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);
        $invoice = $this->autopayInvoice($company);

        $this->actingAs($owner)->get(route('clients.invoice', [$workspace, $company, $invoice]))->assertInertia(fn (Assert $page) => $page
            ->where('autopay.state', 'will_charge')
            ->where('autopay.skip_href', route('agent-api.v1.invoices.autopay.skip', [$workspace, $invoice->public_id], absolute: false)));
    }
}
