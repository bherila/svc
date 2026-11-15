<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\ManualClientEmailBudget;
use App\Services\Billing\Reminders\PaymentReminderService;
use App\Support\Billing\InvoiceEmailDraft;
use App\Support\Billing\Reminders\PaymentReminderTrigger;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\Concerns\BuildsPaymentReminders;
use Tests\TestCase;

/**
 * The manual client-email budget is checked under the workspace's budget key,
 * in the transaction that records the send, and still bounds what a workspace
 * emails by hand. Contention between two sessions is exercised on MariaDB by
 * `ManualClientEmailBudgetConcurrencyTest`.
 */
final class ManualClientEmailBudgetTest extends TestCase
{
    use BuildsPaymentReminders;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        LockOrderRecorder::stop();

        parent::tearDown();
    }

    public function test_the_budget_key_is_locked_after_the_invoice_and_advanced_with_the_insert_before_commit(): void
    {
        Mail::fake();
        [$workspace, $invoice] = $this->issuedInvoice();
        $baseline = DB::transactionLevel();
        $events = [];
        Event::listen(function (QueryExecuted $query) use (&$events): void {
            $sql = strtolower(ltrim($query->sql));
            if (str_starts_with($sql, 'update') && str_contains($sql, 'client_email_budget_locks')) {
                $events[] = ['key advanced', $query->connection->transactionLevel()];
            }
            if (str_starts_with($sql, 'insert') && str_contains($sql, 'client_invoice_email_deliveries')) {
                $events[] = ['delivery inserted', $query->connection->transactionLevel()];
            }
        });
        Event::listen(function (TransactionCommitted $event) use (&$events, $baseline): void {
            if ($event->connection->transactionLevel() === $baseline) {
                $events[] = ['committed', $baseline];
            }
        });

        LockOrderRecorder::start();
        app(InvoiceEmailService::class)->send($invoice, $this->draft(['one@budget.example.test', 'two@budget.example.test']), $workspace);
        $sequences = LockOrderRecorder::sequences();
        LockOrderRecorder::stop();

        $recording = array_values(array_filter($sequences, static fn (array $sequence): bool => in_array(LockResource::ClientEmailBudgetLock, $sequence, true)));
        $this->assertCount(1, $recording, 'One transaction locks the budget key.');
        $sequence = $recording[0];
        $this->assertSame(LockResource::ClientInvoice, $sequence[0], 'The invoice is locked first, as LockResource orders them.');
        $this->assertGreaterThan(
            array_search(LockResource::ClientInvoice, $sequence, true),
            array_search(LockResource::ClientEmailBudgetLock, $sequence, true),
        );

        // The key is advanced and the delivery inserted inside that transaction,
        // and both before the commit that releases the key.
        $first = array_slice($events, 0, 3);
        $this->assertSame([
            ['key advanced', $baseline + 1],
            ['delivery inserted', $baseline + 1],
            ['committed', $baseline],
        ], $first);
        $this->assertSame(2, (int) DB::table('client_email_budget_locks')->where('workspace_id', $workspace->id)->value('admitted_recipients'));
    }

    public function test_the_limit_holds_across_invoices_and_each_workspace_has_its_own(): void
    {
        Mail::fake();
        config(['svc.client_email.daily_recipients' => 3]);
        [$workspace, $first] = $this->issuedInvoice();
        $second = $this->issuedInvoiceIn($workspace, $first->clientCompany);
        [$otherWorkspace, $otherInvoice] = $this->issuedInvoice();
        $emails = app(InvoiceEmailService::class);

        $emails->send($first, $this->draft(['one@budget.example.test', 'two@budget.example.test']), $workspace);

        try {
            $emails->send($second, $this->draft(['three@budget.example.test', 'four@budget.example.test']), $workspace);
            $this->fail('A send past the workspace limit was admitted.');
        } catch (DomainException $refused) {
            $this->assertStringContainsString('limit of 3 recipients', $refused->getMessage());
        }
        // Refused before the insert, and the key was not advanced for it.
        $this->assertSame(0, ClientInvoiceEmailDelivery::query()->where('workspace_id', $workspace->id)->where('client_invoice_id', $second->id)->count());
        $this->assertSame(2, (int) DB::table('client_email_budget_locks')->where('workspace_id', $workspace->id)->value('admitted_recipients'));

        $emails->send($second, $this->draft(['three@budget.example.test']), $workspace);
        $this->assertSame(3, app(ManualClientEmailBudget::class)->usedInLastDay($workspace->id));

        // Another workspace's sends neither count here nor are counted against.
        $emails->send($otherInvoice, $this->draft(['one@other.example.test', 'two@other.example.test', 'three@other.example.test']), $otherWorkspace);
        $this->assertSame(3, app(ManualClientEmailBudget::class)->usedInLastDay($otherWorkspace->id));
        $this->assertSame(3, (int) DB::table('client_email_budget_locks')->where('workspace_id', $otherWorkspace->id)->value('admitted_recipients'));
        $this->assertSame(3, (int) DB::table('client_email_budget_locks')->where('workspace_id', $workspace->id)->value('admitted_recipients'));
    }

    public function test_a_manual_reminder_is_admitted_under_the_same_key_after_its_invoice(): void
    {
        // 10:00 in New York on the due date, within sending hours.
        Date::setTestNow('2030-03-15 14:00:00');
        Mail::fake();
        [$workspace, $owner, , $invoice] = $this->reminderFixture();

        LockOrderRecorder::start();
        $reminder = app(PaymentReminderService::class)->register($workspace, $invoice, $owner, PaymentReminderTrigger::Website);
        $sequences = LockOrderRecorder::sequences();
        LockOrderRecorder::stop();

        $recording = array_values(array_filter($sequences, static fn (array $sequence): bool => in_array(LockResource::ClientEmailBudgetLock, $sequence, true)));
        $this->assertCount(1, $recording);
        $this->assertSame([LockResource::ClientInvoice, LockResource::ClientEmailBudgetLock], array_values(array_unique($recording[0], SORT_REGULAR)));
        $this->assertSame(
            count($reminder->recipients),
            (int) DB::table('client_email_budget_locks')->where('workspace_id', $workspace->id)->value('admitted_recipients'),
        );
    }

    public function test_an_admission_rolled_back_with_its_transaction_is_undone(): void
    {
        [$workspace] = $this->issuedInvoice();
        $budget = app(ManualClientEmailBudget::class);

        try {
            DB::transaction(function () use ($budget, $workspace): void {
                $this->assertTrue($budget->admit($workspace->id, 2));

                throw new DomainException('Synthetic failure after admission.');
            });
        } catch (DomainException) {
        }

        $this->assertNull(DB::table('client_email_budget_locks')->where('workspace_id', $workspace->id)->value('admitted_recipients'));
    }

    public function test_one_transaction_admits_a_workspace_once(): void
    {
        [$workspace] = $this->issuedInvoice();
        [$otherWorkspace] = $this->issuedInvoice();
        $budget = app(ManualClientEmailBudget::class);

        DB::transaction(function () use ($budget, $workspace, $otherWorkspace): void {
            $this->assertTrue($budget->admit($workspace->id, 1));
            // Another workspace's key is its own admission.
            $this->assertTrue($budget->admit($otherWorkspace->id, 1));
            // A savepoint shares the transaction's snapshot, so it is the same transaction.
            $this->assertThrows(
                fn () => DB::transaction(fn (): bool => $budget->admit($workspace->id, 1)),
                LogicException::class,
                'already called for this workspace in this transaction',
            );
        });
        $this->assertSame(1, (int) DB::table('client_email_budget_locks')->where('workspace_id', $workspace->id)->value('admitted_recipients'));

        // A later transaction starts clean.
        DB::transaction(function () use ($budget, $workspace): void {
            $this->assertTrue($budget->admit($workspace->id, 2));
        });
        $this->assertSame(3, (int) DB::table('client_email_budget_locks')->where('workspace_id', $workspace->id)->value('admitted_recipients'));
    }

    public function test_a_refused_admission_still_counts_as_the_transactions_one(): void
    {
        config(['svc.client_email.daily_recipients' => 1]);
        [$workspace] = $this->issuedInvoice();
        $budget = app(ManualClientEmailBudget::class);

        // The refusal's upsert has written the key, so a retry here would read its own write.
        DB::transaction(function () use ($budget, $workspace): void {
            $this->assertFalse($budget->admit($workspace->id, 2));
            $this->assertThrows(fn () => $budget->admit($workspace->id, 1), LogicException::class);
        });
    }

    /** @param  list<string>  $recipients */
    private function draft(array $recipients): InvoiceEmailDraft
    {
        return InvoiceEmailDraft::of($recipients, [], 'Synthetic budget invoice', null);
    }

    /** @return array{0: Workspace, 1: ClientInvoice} */
    private function issuedInvoice(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic budget workspace', 'slug' => 'synthetic-budget-'.str()->random(8)]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic budget client', 'slug' => 'synthetic-budget-client']);

        return [$workspace, $this->issuedInvoiceIn($workspace, $company)];
    }

    private function issuedInvoiceIn(Workspace $workspace, ClientCompany $company): ClientInvoice
    {
        $service = app(InvoiceLifecycleService::class);

        return $service->issue($service->createDraft($workspace, $company, [
            'currency' => 'USD',
            'invoice_number' => 'INV-SYN-'.str()->random(12),
        ], [[
            'type' => 'adjustment',
            'description' => 'Synthetic budget service',
            'quantity' => 1,
            'unit_amount' => 100,
        ]]), $workspace);
    }
}
