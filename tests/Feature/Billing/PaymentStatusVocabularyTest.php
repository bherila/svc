<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\OverpaymentCreditService;
use App\Services\Billing\StripePaymentIntentService;
use App\Support\Billing\InvoicePaymentStatus;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A payment status this application cannot read is never written, and never
 * silently valued at zero.
 *
 * The paid amount on an invoice is derived by a *positive* filter over
 * succeeded payments, so any other value contributes nothing. That is correct
 * for the five recognised non-success states and wrong for a sixth thing
 * nobody has classified: "this row moved no money" is a claim, and an
 * uninterpretable state cannot support it.
 *
 * It was reachable through an ordinary supported path. `StorePaymentRequest`
 * and the API listing constrained the value; `InvoiceLifecycleService` and
 * `svc:billing:payment` did not, and the column is an unconstrained
 * `varchar(24)`. So `--status=paid` - the intuitive *invoice* word - or a
 * mistyped `suceeded` was accepted end to end, skipped the over-balance check
 * (which compared against the literal `succeeded`), and produced a row the
 * recomputation ignored. The invoice stayed `issued` for its whole balance
 * with the payment sitting on it, so money genuinely received was invisible
 * and the same balance could be collected a second time.
 *
 * This is the payment-row counterpart of the invoice-status refusal in
 * {@see UndatedPeriodIssueRefusalTest}, failing the other way: there the
 * danger was rewriting a state that could not be read, here it is valuing one
 * at zero.
 */
final class PaymentStatusVocabularyTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Payments', 'slug' => 'payments']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Paying Client',
            'slug' => 'paying-client',
        ]);
    }

    /**
     * Values an operator plausibly supplies, none of which are payment statuses.
     *
     * `paid` is the invoice vocabulary reached for by someone recording a
     * payment; `suceeded` is the one-character typo of the default; `settled`
     * is the shape an import or a future provider mapping arrives in.
     */
    public static function unrecognisedStatuses(): iterable
    {
        yield 'the invoice word' => ['paid'];
        yield 'a typo of the default' => ['suceeded'];
        yield 'an imported vocabulary' => ['settled'];
        yield 'empty, but supplied' => [''];
    }

    /** Each recognised status, so the refusal cannot be a blanket one. */
    public static function recognisedStatuses(): iterable
    {
        foreach (InvoicePaymentStatus::cases() as $status) {
            yield $status->value => [$status->value];
        }
    }

    #[DataProvider('unrecognisedStatuses')]
    public function test_apply_payment_refuses_an_unrecognised_payment_status(string $status): void
    {
        $invoice = $this->issuedInvoice();
        $statusBefore = $invoice->status;
        $balanceBefore = $invoice->balance_amount;
        $activitiesBefore = ClientCompanyActivity::query()->count();

        try {
            app(InvoiceLifecycleService::class)->applyPayment($invoice, [
                'amount' => 1000,
                'currency' => 'USD',
                'method' => 'ach',
                'status' => $status,
                'received_on' => '2026-08-15',
            ], $this->workspace);
            $this->fail('An unknown payment status must not be persisted.');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString('Unsupported payment status', $refusal->getMessage());
            $this->assertStringContainsString('succeeded', $refusal->getMessage(), 'It names what is accepted');
        }

        $this->assertSame(0, $invoice->payments()->count(), 'The payment insert rolls back');
        $fresh = $invoice->refresh();
        $this->assertSame($statusBefore, $fresh->status);
        $this->assertSame($balanceBefore, $fresh->balance_amount);
        $this->assertSame($activitiesBefore, ClientCompanyActivity::query()->count());
    }

    /**
     * The console path, which is where this was actually exposed.
     *
     * `svc:billing:payment --status=paid` passed the option straight through.
     * The service now parses it, so this pins the application route rather
     * than only the service that closed it.
     */
    public function test_the_console_command_cannot_record_a_payment_of_an_unknown_status(): void
    {
        $invoice = $this->issuedInvoice();

        try {
            $this->artisan('svc:billing:payment', [
                'invoice' => $invoice->public_id,
                'amount' => '10000',
                'currency' => 'USD',
                'method' => 'ach',
                '--workspace' => $this->workspace->public_id,
                '--status' => 'paid',
            ])->run();
            $this->fail('The command must not record a payment of an unknown status.');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString('Unsupported payment status', $refusal->getMessage());
        }

        $this->assertSame(0, $invoice->payments()->count());
        $this->assertSame('issued', $invoice->refresh()->status);
    }

    #[DataProvider('recognisedStatuses')]
    public function test_every_recognised_status_is_still_accepted(string $status): void
    {
        $invoice = $this->issuedInvoice();

        $payment = app(InvoiceLifecycleService::class)->applyPayment($invoice, [
            'amount' => 10000,
            'currency' => 'USD',
            'method' => 'ach',
            'status' => $status,
            'received_on' => '2026-08-15',
        ], $this->workspace);

        $this->assertSame($status, $payment->status);
        $this->assertSame(
            $status === 'succeeded' ? 'paid' : 'issued',
            $invoice->refresh()->status,
            'Only a succeeded payment moves the invoice',
        );
    }

    /**
     * An existing unreadable payment stops the recomputation instead of being
     * counted as nothing.
     *
     * Write-side validation does not reach this row: an import, a migration or
     * a hand-repair can put it here without passing through `applyPayment()`.
     * The invoice below is *paid*, and its paid state rests entirely on that
     * one row. Before this guard, adding any further payment recomputed the
     * balance without it and reopened the full 10000 the client had already
     * settled - which is the collect-it-twice shape, arrived at from stored
     * data rather than a bad write.
     */
    public function test_an_existing_unknown_payment_blocks_recomputation(): void
    {
        [$invoice] = $this->paidBySettledPayment();
        $activitiesBefore = ClientCompanyActivity::query()->count();

        try {
            app(InvoiceLifecycleService::class)->applyPayment($invoice, [
                'amount' => 500,
                'currency' => 'USD',
                'method' => 'ach',
                'status' => 'pending',
                'received_on' => '2026-08-16',
            ], $this->workspace);
            $this->fail('A balance cannot be recomputed over an unreadable payment.');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString('unrecognised status', $refusal->getMessage());
            $this->assertStringContainsString('settled', $refusal->getMessage());
        }

        $fresh = $invoice->refresh();
        $this->assertSame('paid', $fresh->status, 'The invoice does not reopen');
        $this->assertSame(10000, (int) $fresh->paid_amount);
        $this->assertSame(0, (int) $fresh->balance_amount);
        $this->assertSame(1, $invoice->payments()->count(), 'The new payment rolls back');
        $this->assertSame('settled', (string) $invoice->payments()->sole()->status, 'The unknown row is untouched');
        $this->assertSame($activitiesBefore, ClientCompanyActivity::query()->count());
    }

    /**
     * Classifying the unreadable row is still a repair, not a dead end.
     *
     * The refusal above lives in the recomputation, and `setPaymentStatus()`
     * recomputes *after* it writes the replacement - so the operation that
     * fixes the row is the one operation the refusal must not block. Checking
     * before the save would make the inconsistency permanent, which is a worse
     * failure than the one being fixed: there would be no in-app way out of it
     * at all.
     */
    public function test_reclassifying_the_only_unknown_payment_is_a_repair(): void
    {
        [$invoice, $payment] = $this->paidBySettledPayment();

        $repaired = app(InvoiceLifecycleService::class)
            ->setPaymentStatus($payment, 'succeeded', $this->workspace);

        $this->assertSame('succeeded', $repaired->status);
        $fresh = $invoice->refresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame(10000, (int) $fresh->paid_amount);
        $this->assertSame(0, (int) $fresh->balance_amount);
    }

    /**
     * A second unknown row still blocks that repair.
     *
     * One at a time is a repair; two is a balance nobody can total, and
     * reclassifying either of them alone would commit a paid amount computed
     * over the other.
     */
    public function test_a_second_unknown_payment_still_blocks_the_repair(): void
    {
        [$invoice, $payment] = $this->paidBySettledPayment();
        $other = $invoice->payments()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'reconciling',
            'amount' => 2500,
            'refunded_amount' => 0,
            'currency' => 'USD',
            'method' => 'ach',
            'received_on' => '2026-08-16',
        ]);

        try {
            app(InvoiceLifecycleService::class)->setPaymentStatus($payment, 'succeeded', $this->workspace);
            $this->fail('A repair must not commit a balance computed over another unreadable row.');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString('unrecognised status', $refusal->getMessage());
        }

        $this->assertSame('settled', $payment->refresh()->status, 'The reclassification rolls back');
        $this->assertSame('reconciling', $other->refresh()->status);
        $this->assertSame('paid', $invoice->refresh()->status);
    }

    /**
     * Voiding is refused while a payment cannot be read.
     *
     * `void()` asks "is money in flight" with `where('status', 'pending')`, a
     * positive filter, so an unreadable row is not seen as pending and the
     * invoice voids out from under whatever that payment turns out to be. The
     * guard exists precisely to stop that, and an unclassifiable row is the one
     * case where it cannot say the answer is no.
     */
    public function test_an_unreadable_payment_stops_an_invoice_being_voided(): void
    {
        [$invoice, $payment] = $this->paidBySettledPayment();
        $invoice->forceFill(['status' => 'issued', 'paid_amount' => 0, 'balance_amount' => 10000])->save();

        try {
            app(InvoiceLifecycleService::class)->void($invoice->refresh(), $this->workspace, 'Cleanup');
            $this->fail('An invoice carrying an unreadable payment must not be voided.');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString('unrecognised status', $refusal->getMessage());
            $this->assertStringContainsString('must not be voided', $refusal->getMessage());
        }

        $this->assertSame('issued', $invoice->refresh()->status);
        $this->assertNull($invoice->voided_at);

        // Control: classify it and the void the operator wanted goes through,
        // so the refusal names a repair that actually releases the invoice.
        app(InvoiceLifecycleService::class)
            ->setPaymentStatus($payment, 'canceled', $this->workspace);
        app(InvoiceLifecycleService::class)->void($invoice->refresh(), $this->workspace, 'Cleanup');
        $this->assertSame('void', $invoice->refresh()->status);
    }

    /**
     * Credit is not derived over a payment nobody can read.
     *
     * The overpaid figure is a positive filter over settled payments, so an
     * unreadable row contributes nothing and the overpayment it may represent
     * disappears - the client is shown less credit than they paid for, and
     * nothing reports the difference. This is the same defect as the balance
     * one, on the number that decides what the *next* invoice charges.
     */
    public function test_an_unreadable_payment_stops_credit_being_derived(): void
    {
        [$invoice, $payment] = $this->paidBySettledPayment();

        try {
            app(OverpaymentCreditService::class)
                ->availableCreditForCompany($this->company, 'USD');
            $this->fail('Credit must not be derived over an unreadable payment.');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString('unrecognised status', $refusal->getMessage());
            $this->assertStringContainsString('overpaid', $refusal->getMessage());
        }

        app(InvoiceLifecycleService::class)
            ->setPaymentStatus($payment, 'succeeded', $this->workspace);
        $this->assertSame(
            0.0,
            app(OverpaymentCreditService::class)->availableCreditForCompany($this->company, 'USD'),
            'Classified, the ledger builds again',
        );
        $this->assertSame('paid', $invoice->refresh()->status);
    }

    /**
     * A new Stripe intent is not minted beside a payment nobody can read.
     *
     * Pending payments reserve the balance so two tabs cannot each charge it in
     * full. That sum is a positive filter too, so an unreadable row reserves
     * nothing, the charge amount comes back as the whole balance, and the
     * double charge the reservation was added to prevent is reached by the one
     * route it does not cover. Refused before Stripe is called at all.
     */
    public function test_an_unreadable_payment_stops_a_new_payment_intent(): void
    {
        [$invoice] = $this->paidBySettledPayment();
        $invoice->forceFill(['status' => 'issued', 'paid_amount' => 0, 'balance_amount' => 10000])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('must not be created');
        app(StripePaymentIntentService::class)
            ->create($invoice->refresh(), $this->workspace, null, 'a-key');
    }

    /**
     * The vocabulary is not written down anywhere else.
     *
     * The same guard {@see InvoiceStatusVocabularyTest} keeps on invoice
     * statuses, for the same reason: this list was written in four places and
     * the four disagreed about who enforced it, which is how an unvalidated
     * `--status` reached an unconstrained column. A hand-written list fails
     * silently here, by dropping a payment out of a money decision rather than
     * by erroring.
     *
     * @var list<string>
     */
    private const GUARDED = [
        'app/Services/Billing/InvoiceLifecycleService.php',
        'app/Services/Billing/OverpaymentCreditService.php',
        'app/Services/Billing/StripePaymentIntentService.php',
        'app/Services/Billing/StripeWebhookService.php',
        'app/Services/Finance/PaymentReconciliationService.php',
        'app/Http/Controllers/Api/V1/InvoicePaymentController.php',
        'app/Http/Requests/Billing/StorePaymentRequest.php',
    ];

    public function test_no_service_enumerates_payment_statuses_by_hand(): void
    {
        $offenders = [];

        foreach (self::GUARDED as $relative) {
            $contents = file_get_contents(base_path($relative));
            if ($contents === false) {
                $this->fail("Could not read {$relative}");
            }

            foreach (explode("\n", $contents) as $number => $line) {
                if (preg_match_all("/'(pending|succeeded|failed|refunded|disputed|canceled)'/", $line, $matches) >= 1
                    && count($matches[1]) >= 2) {
                    $offenders[] = sprintf('%s:%d  %s', $relative, $number + 1, trim($line));
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These lines enumerate payment statuses by hand:\n\n%s\n\n"
            .'Use InvoicePaymentStatus - its cases, ::all(), ::contributesToPaidAmount(), or the '
            .'ofUnreadableStatus() scope for the negative question.',
            implode("\n", $offenders),
        ));
    }

    /** The vocabulary itself, so a seventh value is a deliberate edit. */
    public function test_the_recognised_vocabulary(): void
    {
        $this->assertSame(
            ['pending', 'succeeded', 'failed', 'refunded', 'disputed', 'canceled'],
            InvoicePaymentStatus::all(),
        );
        $this->assertTrue(InvoicePaymentStatus::Succeeded->contributesToPaidAmount());
        foreach (InvoicePaymentStatus::cases() as $status) {
            if ($status !== InvoicePaymentStatus::Succeeded) {
                $this->assertFalse($status->contributesToPaidAmount(), $status->value.' moves no money');
            }
        }
    }

    /** An issued, unlinked ad-hoc invoice worth 10000. */
    private function issuedInvoice(): ClientInvoice
    {
        $this->sequence++;
        $lifecycle = app(InvoiceLifecycleService::class);
        $invoice = $lifecycle->createDraft(
            $this->workspace,
            $this->company,
            [
                'invoice_number' => 'PAY-'.$this->sequence,
                'currency' => 'USD',
                'service_period_start' => '2026-08-01',
                'service_period_end' => '2026-08-31',
            ],
            [['type' => 'adjustment', 'description' => 'Work', 'quantity' => 1, 'unit_amount' => 10000]],
        );

        return $lifecycle->issue($invoice, $this->workspace);
    }

    /**
     * An invoice whose paid state rests on a single unreadable payment.
     *
     * Forced, because no supported path can now create it - which is the point:
     * the row this guards against is stored data, not a write this application
     * would make.
     *
     * @return array{ClientInvoice, ClientInvoicePayment}
     */
    private function paidBySettledPayment(): array
    {
        $invoice = $this->issuedInvoice();
        $payment = $invoice->payments()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'pending',
            'amount' => 10000,
            'refunded_amount' => 0,
            'currency' => 'USD',
            'method' => 'ach',
            'received_on' => '2026-08-15',
        ]);
        $payment->forceFill(['status' => 'settled'])->save();
        $invoice->forceFill([
            'status' => 'paid',
            'paid_amount' => 10000,
            'balance_amount' => 0,
        ])->save();

        return [$invoice->refresh(), $payment->refresh()];
    }
}
