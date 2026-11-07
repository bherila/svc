<?php

namespace Tests\Feature\Billing\Autopay;

use App\Models\ClientAutopayConsent;
use App\Models\InvoiceAutopayAttempt;
use App\Services\Billing\Autopay\AutopayChargeService;
use App\Services\Billing\Autopay\AutopaySkipService;
use App\Services\Square\SquareLinkService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\Presenters\AutopayPresenter;
use App\Support\Billing\Autopay\AutopayAttemptState;
use App\Support\Billing\Autopay\AutopayChargeResult;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAutopay;
use Tests\Concerns\FakesSquare;
use Tests\TestCase;

/** Tenancy, Square and the skip decision for autopay (#636). */
final class AutopayIsolationTest extends TestCase
{
    use BuildsAutopay;
    use FakesSquare;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->restoreAutopayStripe();
        parent::tearDown();
    }

    public function test_each_workspace_is_charged_only_its_own_invoices_against_its_own_customers_and_methods(): void
    {
        $this->travelTo('2030-10-03 18:00:00');
        [$workspaceA, , $companyA, $contactA] = $this->autopayFixture('alpha');
        [$workspaceB, , $companyB, $contactB] = $this->autopayFixture('bravo');
        $this->turnAutopayOn($workspaceA, $companyA, $contactA, 500_000);
        $this->turnAutopayOn($workspaceB, $companyB, $contactB, 500_000);
        $invoiceA = $this->autopayInvoice($companyA, 'INV-SAME', 111_000);
        $invoiceB = $this->autopayInvoice($companyB, 'INV-SAME', 222_000);
        $this->travelTo('2030-12-01 15:00:00');
        $this->fakeAutopayStripe();

        $outcomes = app(AutopayChargeService::class)->run(50, true);

        $this->assertCount(2, $outcomes);
        $byInvoice = [];
        foreach ($this->autopayCharges() as $call) {
            $byInvoice[$call['params']['metadata']['invoice_public_id']] = $call['params'];
        }
        $this->assertSame([$workspaceA->public_id, 'cus_synthetic_alpha', 'pm_synthetic_alpha', 111_000], [
            $byInvoice[$invoiceA->public_id]['metadata']['workspace_public_id'], $byInvoice[$invoiceA->public_id]['customer'],
            $byInvoice[$invoiceA->public_id]['payment_method'], $byInvoice[$invoiceA->public_id]['amount'],
        ]);
        $this->assertSame([$workspaceB->public_id, 'cus_synthetic_bravo', 'pm_synthetic_bravo', 222_000], [
            $byInvoice[$invoiceB->public_id]['metadata']['workspace_public_id'], $byInvoice[$invoiceB->public_id]['customer'],
            $byInvoice[$invoiceB->public_id]['payment_method'], $byInvoice[$invoiceB->public_id]['amount'],
        ]);
        $this->assertSame(1, InvoiceAutopayAttempt::query()->where('workspace_id', $workspaceA->id)->count());
        $this->assertSame(1, InvoiceAutopayAttempt::query()->where('workspace_id', $workspaceB->id)->count());
    }

    public function test_one_workspaces_consent_and_charges_are_never_read_into_anothers_company(): void
    {
        $this->travelTo('2030-10-03 18:00:00');
        [$workspaceA, , $companyA, $contactA] = $this->autopayFixture('alpha');
        [$workspaceB, , $companyB] = $this->autopayFixture('bravo');
        $this->turnAutopayOn($workspaceA, $companyA, $contactA);
        $this->autopayInvoice($companyA, 'INV-A');
        $presenter = app(AutopayPresenter::class);

        $mine = $presenter->company($workspaceA, $companyA->refresh());
        $this->assertCount(1, $mine['consents']);
        $this->assertCount(1, $mine['upcoming']);
        // Asked about another workspace's company with this workspace's identity: nothing of A is shown.
        $theirs = $presenter->company($workspaceB, $companyB);
        $this->assertSame([false, [], []], [$theirs['enabled'], $theirs['consents'], $theirs['upcoming']]);
        $this->assertSame([], $presenter->company($workspaceB, $companyA)['consents']);
    }

    public function test_a_row_cannot_name_a_company_or_invoice_of_another_workspace(): void
    {
        [$workspaceA, , $companyA] = $this->autopayFixture('alpha');
        [$workspaceB, , $companyB] = $this->autopayFixture('bravo');
        $invoiceB = $this->autopayInvoice($companyB);

        foreach ([
            fn () => ClientAutopayConsent::query()->create([
                'workspace_id' => $workspaceA->id, 'client_company_id' => $companyB->id, 'action' => 'enabled', 'actor_role' => 'system',
                'method' => 'automatic', 'occurred_at' => now(),
            ]),
            fn () => InvoiceAutopayAttempt::query()->create([
                'workspace_id' => $workspaceA->id, 'client_invoice_id' => $invoiceB->id, 'client_company_id' => $companyA->id,
                'state' => 'claimed', 'claimed_at' => now(),
            ]),
            fn () => InvoiceAutopayAttempt::query()->create([
                'workspace_id' => $workspaceA->id, 'client_invoice_id' => $this->autopayInvoice($companyA, 'INV-A')->id, 'client_company_id' => $companyB->id,
                'state' => 'claimed', 'claimed_at' => now(),
            ]),
        ] as $write) {
            try {
                $write();
                $this->fail('A cross-workspace row was accepted.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame($workspaceB->id, $companyB->workspace_id);
    }

    public function test_skipping_cannot_reach_another_workspaces_invoice(): void
    {
        [$workspaceA, $ownerA, $companyA, $contactA] = $this->autopayFixture('alpha');
        [, , $companyB] = $this->autopayFixture('bravo');
        $this->turnAutopayOn($workspaceA, $companyA, $contactA);
        $foreign = $this->autopayInvoice($companyB);

        try {
            app(AutopaySkipService::class)->skip($workspaceA, $foreign, $ownerA);
            $this->fail('Another workspace\'s invoice was skipped.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame(0, InvoiceAutopayAttempt::query()->count());
        }
    }

    public function test_a_skipped_invoice_is_never_charged_and_a_charged_one_cannot_be_skipped(): void
    {
        $this->travelTo('2030-10-03 18:00:00');
        [$workspace, $owner, $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);
        $skipped = $this->autopayInvoice($company, 'INV-SKIP');
        $charged = $this->autopayInvoice($company, 'INV-CHARGE');
        $skips = app(AutopaySkipService::class);

        $attempt = $skips->skip($workspace, $skipped, $owner, AgentApiVersion::for($skipped));
        $this->assertSame([AutopayAttemptState::Skipped, $owner->id], [$attempt->state, $attempt->skipped_by_user_id]);
        $this->assertSame($attempt->id, $skips->skip($workspace, $skipped->fresh(), $owner)->id, 'Skipping twice is the same skip.');

        $this->travelTo('2030-12-01 15:00:00');
        $this->fakeAutopayStripe();
        $outcomes = app(AutopayChargeService::class)->run(50, true);
        $this->assertSame(['INV-CHARGE'], array_map(static fn ($outcome): string => $outcome->invoiceNumber, $outcomes));
        $this->assertSame(AutopayChargeResult::Submitted, $outcomes[0]->result);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('can no longer be skipped');
        $skips->skip($workspace, $charged->fresh(), $owner);
    }

    public function test_only_an_open_invoice_of_a_client_with_autopay_on_can_be_skipped(): void
    {
        [$workspace, $owner, $company, $contact] = $this->autopayFixture();
        $paid = $this->autopayInvoice($company, 'INV-PAID', status: 'paid');
        $paid->forceFill(['balance_amount' => 0])->save();
        $open = $this->autopayInvoice($company, 'INV-OPEN');
        $skips = app(AutopaySkipService::class);

        foreach ([$paid, $open] as $invoice) {
            try {
                $skips->skip($workspace, $invoice->fresh(), $owner);
                $this->fail('A refusal was expected for '.$invoice->invoice_number);
            } catch (DomainException) {
                $this->assertSame(0, InvoiceAutopayAttempt::query()->count());
            }
        }
        $this->turnAutopayOn($workspace, $company, $contact);
        $this->expectException(DomainException::class);
        $skips->skip($workspace, $paid->fresh(), $owner);
    }

    public function test_an_invoice_square_collects_is_never_charged_by_autopay(): void
    {
        $this->travelTo('2030-10-03 18:00:00');
        [$workspace, $owner, $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);
        $invoice = $this->autopayInvoice($company);
        $this->connectSquare($workspace);
        app(SquareLinkService::class)->link($workspace, $invoice, $owner, AgentApiVersion::for($invoice->refresh()), new SquareInvoiceReference('inv:0-autopay1', '000777-A-0001', 'USD', SquareMatchMethod::Manual), false);
        $this->travelTo('2030-12-01 15:00:00');
        $this->fakeAutopayStripe();

        $this->assertSame([], app(AutopayChargeService::class)->run(50, true));
        $this->assertSame([], $this->autopayStripeCalls);
        $this->assertSame(0, InvoiceAutopayAttempt::query()->count());
    }

    public function test_a_company_without_a_default_method_or_stripe_charges_nothing(): void
    {
        $this->travelTo('2030-10-03 18:00:00');
        [$workspace, , $company, $contact, $method] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);
        $this->autopayInvoice($company);
        $this->travelTo('2030-12-01 15:00:00');
        $this->fakeAutopayStripe();

        $method->forceFill(['is_default' => false])->save();
        $this->assertSame([], app(AutopayChargeService::class)->run(50, true));

        $method->forceFill(['is_default' => true])->save();
        config(['services.stripe.secret_key' => null]);
        $this->assertSame([], app(AutopayChargeService::class)->run(50, true));
        $this->assertSame([], $this->autopayStripeCalls);
    }
}
