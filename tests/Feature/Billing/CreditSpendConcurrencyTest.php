<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\OverpaymentCreditService;
use App\Support\Billing\CreditPoolChanged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * One 100.00 credit, two drafts that were each offered it, and two separate
 * MariaDB processes issuing them.
 *
 * `issue()` serialises credit spending on the company row, but the credit
 * ledger is computed with ordinary reads. Under REPEATABLE READ an ordinary
 * read returns the transaction's snapshot - fixed by its first ordinary read -
 * and acquiring a lock later does not refresh it. So a transaction whose
 * snapshot predates another issue's commit can wait for the company lock, get
 * it, and still see the credit unspent.
 *
 * Two ways to have such a snapshot:
 *
 * - `after-workspace-read`: issue() itself, held just after its ordinary read
 *   of the workspace and before it reaches the company.
 * - `outer-snapshot`: a caller whose own transaction read something before
 *   calling issue() - the shape of the schedule generator, which plans inside
 *   its transaction and then issues.
 *
 * The credit is an invoice paid 100.00 over, written as rows. The application
 * refuses to record an overpayment today (`applyPayment()` and
 * `setPaymentStatus()` both cap a payment at the balance), so the only credit
 * a live pool can hold is overpayment carried in from imported history - which
 * is what these rows represent, and how `BillingTenantIsolationTest` seeds it.
 */
final class CreditSpendConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    /** Bounded wait for the unpaused issue to finish while the other is held. */
    private const COMPETITOR_SECONDS = 5.0;

    /**
     * The mode, and which path the fixed code has to take for it.
     *
     * Held inside issue(), the held transaction already has the company lock
     * when it pauses, so the competitor cannot commit until it is released: the
     * held issue spends the credit and the competitor issues without it. With a
     * snapshot from an outer transaction, nothing is locked yet when it pauses,
     * the competitor spends the credit and commits, and the held issue has to
     * refuse - its snapshot cannot be refreshed.
     *
     * @return iterable<string, array{string, bool, string}>
     */
    public static function snapshots(): iterable
    {
        yield 'held after issue() reads the workspace' => ['after-workspace-read', false, 'success'];
        yield 'snapshot taken by an outer transaction' => ['outer-snapshot', true, 'refused'];
    }

    #[DataProvider('snapshots')]
    public function test_one_credit_cannot_be_spent_by_two_concurrent_issues(string $mode, bool $competitorCommitsWhileHeld, string $heldOutcome): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Snapshot visibility under REPEATABLE READ is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('credit_race');
        Artisan::call('migrate', ['--database' => 'credit_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('credit_race');
        Schema::clearResolvedInstance('db.schema');
        $server = $this->serverFacts();
        $processes = [];
        $barrier = sys_get_temp_dir().'/svc-credit-race-'.Str::lower(Str::random(16));
        $paused = $barrier.'-paused';
        $release = $barrier.'-release';
        try {
            $workspace = Workspace::query()->create(['name' => 'Synthetic credit race', 'slug' => 'synthetic-credit-race']);
            $workspace->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create([
                'workspace_id' => $workspace->id, 'name' => 'Synthetic credit client', 'slug' => 'synthetic-credit-client',
            ]);
            $this->overpaidBy10000($company);
            $credits = app(OverpaymentCreditService::class);
            $held = $this->draftWorth($company, 'SYNTH-CREDIT-HELD', 50000);
            $competitor = $this->draftWorth($company, 'SYNTH-CREDIT-OTHER', 50000);
            // Both drafts are legitimately offered the whole pool.
            $credits->applyCreditsToDraftInvoice($held);
            $credits->applyCreditsToDraftInvoice($competitor);
            $this->assertSame(-10000, $this->creditOn($held));
            $this->assertSame(-10000, $this->creditOn($competitor));

            $first = $this->worker($workspace, $held, $mode, $paused, $release);
            $second = $this->worker($workspace, $competitor, 'plain', null, null);
            $processes = [$first, $second];
            $first->start();
            $this->awaitFile($first, $paused);
            $second->start();
            $deadline = microtime(true) + self::COMPETITOR_SECONDS;
            while ($second->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $competitorCommittedWhileHeld = ! $second->isRunning();
            $this->assertTrue(touch($release), 'Could not release the held issue.');
            $first->wait();
            $second->wait();
            $output = $first->getOutput().$first->getErrorOutput().$second->getOutput().$second->getErrorOutput();
            $context = json_encode(['server' => $server, 'competitor_committed_while_held' => $competitorCommittedWhileHeld], JSON_THROW_ON_ERROR).' '.$output;
            $heldResult = $this->workerResult($first);
            $competitorResult = $this->workerResult($second);

            // A fresh connection, after both transactions have finished.
            DB::purge('credit_race');
            $consumed = -(int) ClientInvoiceLine::query()
                ->join('client_invoices', 'client_invoices.id', '=', 'client_invoice_lines.client_invoice_id')
                ->where('client_invoices.workspace_id', $workspace->id)
                ->whereIn('client_invoices.status', ['issued', 'partially_paid', 'paid'])
                ->where('client_invoice_lines.type', 'credit')
                ->sum('client_invoice_lines.total_amount');

            $this->assertLessThanOrEqual(10000, $consumed, 'The 100.00 credit was spent more than once. '.$context);
            $this->assertSame(10000, $consumed, 'Whichever issue won, the credit was spent once. '.$context);
            $this->assertOutcomeIsDefined($heldResult, $held, $context);
            $this->assertOutcomeIsDefined($competitorResult, $competitor, $context);
            $this->assertSame('success', $competitorResult['outcome'], $context);
            $this->assertSame($competitorCommitsWhileHeld, $competitorCommittedWhileHeld, $context);
            $this->assertSame($heldOutcome, $heldResult['outcome'], $context);
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            @unlink($paused);
            @unlink($release);
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    /**
     * The funding side of the same race: a refund that removes the credit,
     * committed after an outer transaction's snapshot and before its issue.
     *
     * Only the snapshot can see the credit by then. The refund has to move the
     * pool's revision for the issue to know that, which is why every writer
     * that can shrink the pool records the change, not only `issue()`.
     */
    public function test_a_refund_committed_after_the_snapshot_stops_the_credit_being_spent(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Snapshot visibility under REPEATABLE READ is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('credit_race');
        Artisan::call('migrate', ['--database' => 'credit_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('credit_race');
        Schema::clearResolvedInstance('db.schema');
        $server = $this->serverFacts();
        $processes = [];
        $barrier = sys_get_temp_dir().'/svc-credit-race-'.Str::lower(Str::random(16));
        $paused = $barrier.'-paused';
        $release = $barrier.'-release';
        try {
            $workspace = Workspace::query()->create(['name' => 'Synthetic refund race', 'slug' => 'synthetic-refund-race']);
            $workspace->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create([
                'workspace_id' => $workspace->id, 'name' => 'Synthetic refund client', 'slug' => 'synthetic-refund-client',
            ]);
            $payment = $this->overpaidBy10000($company);
            $held = $this->draftWorth($company, 'SYNTH-REFUND-HELD', 50000);
            app(OverpaymentCreditService::class)->applyCreditsToDraftInvoice($held);
            $this->assertSame(-10000, $this->creditOn($held));

            $issuer = $this->worker($workspace, $held, 'outer-snapshot', $paused, $release);
            // Refunding the whole overpayment: 200.00 paid, 100.00 refunded,
            // against a 100.00 invoice - nothing left over.
            $refund = $this->worker($workspace, null, 'refund', null, null, ['payment' => $payment->id, 'refund' => 10000]);
            $processes = [$issuer, $refund];
            $issuer->start();
            $this->awaitFile($issuer, $paused);
            $refund->start();
            $refund->wait();
            $this->assertTrue(touch($release), 'Could not release the held issue.');
            $issuer->wait();
            $output = $issuer->getOutput().$issuer->getErrorOutput().$refund->getOutput().$refund->getErrorOutput();
            $context = json_encode(['server' => $server], JSON_THROW_ON_ERROR).' '.$output;
            $this->assertSame(['outcome' => 'success', 'refunded' => 10000], $this->workerResult($refund), $context);
            $issued = $this->workerResult($issuer);

            DB::purge('credit_race');
            $this->assertSame(0.0, app(OverpaymentCreditService::class)->availableCreditForCompany($company->fresh(), 'USD'), $context);
            $consumed = -(int) ClientInvoiceLine::query()
                ->join('client_invoices', 'client_invoices.id', '=', 'client_invoice_lines.client_invoice_id')
                ->where('client_invoices.workspace_id', $workspace->id)
                ->whereIn('client_invoices.status', ['issued', 'partially_paid', 'paid'])
                ->where('client_invoice_lines.type', 'credit')
                ->sum('client_invoice_lines.total_amount');
            $this->assertSame(0, $consumed, 'Credit the refund removed was spent anyway. '.$context);
            $this->assertSame('refused', $issued['outcome'], $context);
            $this->assertOutcomeIsDefined($issued, $held, $context);
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            @unlink($paused);
            @unlink($release);
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    /**
     * Each issue either issued consistently - its total is the work less
     * whatever credit it still carries - or refused with the defined conflict
     * and left its draft exactly as it was reviewed, with nothing registered
     * for delivery or notification.
     *
     * @param  array<string, mixed>  $result
     */
    private function assertOutcomeIsDefined(array $result, ClientInvoice $invoice, string $context): void
    {
        $row = ClientInvoice::query()->where('workspace_id', $invoice->workspace_id)->findOrFail($invoice->id);

        if ($result['outcome'] === 'success') {
            $this->assertSame('issued', $row->status, $context);
            $this->assertSame(50000 + $this->creditOn($row), (int) $row->total_amount, $context);

            return;
        }

        $this->assertSame('refused', $result['outcome'], $context);
        $this->assertSame(CreditPoolChanged::class, $result['class'], $context);
        $this->assertSame('draft', $row->status, $context);
        $this->assertSame(40000, (int) $row->total_amount, 'A refusal leaves the reviewed draft as it was. '.$context);
        $this->assertSame(-10000, $this->creditOn($row), $context);
        $this->assertSame(0, DB::table('client_invoice_administrator_notifications')->where('client_invoice_id', $invoice->id)->count(), $context);
        $this->assertSame(0, DB::table('client_invoice_email_deliveries')->where('client_invoice_id', $invoice->id)->count(), $context);
    }

    private function overpaidBy10000(ClientCompany $company): ClientInvoicePayment
    {
        $source = ClientInvoice::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'invoice_number' => 'SYNTH-CREDIT-SOURCE',
            'currency' => 'USD',
            'status' => 'paid',
            'invoice_kind' => 'ad_hoc',
            'subtotal_amount' => 10000,
            'tax_amount' => 0,
            'total_amount' => 10000,
            'paid_amount' => 20000,
        ]);
        $payment = $source->payments()->create([
            'workspace_id' => $company->workspace_id,
            'amount' => 20000,
            'refunded_amount' => 0,
            'currency' => 'USD',
            'status' => 'succeeded',
            'method' => 'manual',
            'received_on' => '2024-01-31',
        ]);
        $this->assertSame(100.0, app(OverpaymentCreditService::class)->availableCreditForCompany($company, 'USD'));

        return $payment;
    }

    private function draftWorth(ClientCompany $company, string $number, int $amount): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'invoice_number' => $number,
            'currency' => 'USD',
            'status' => 'draft',
            'invoice_kind' => 'ad_hoc',
            'subtotal_amount' => $amount,
            'tax_amount' => 0,
            'total_amount' => $amount,
        ]);
        ClientInvoiceLine::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_invoice_id' => $invoice->id,
            'type' => 'additional_hours',
            'description' => 'Synthetic work',
            'quantity' => '1',
            'unit_amount' => $amount,
            'tax_amount' => 0,
            'total_amount' => $amount,
            'sort_order' => 0,
        ]);

        return $invoice;
    }

    private function creditOn(ClientInvoice $invoice): int
    {
        return (int) ClientInvoiceLine::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)
            ->where('type', 'credit')
            ->sum('total_amount');
    }

    /** @return array<string, string|null> */
    private function serverFacts(): array
    {
        $row = (array) DB::selectOne('select version() as version, @@tx_isolation as isolation');
        $snapshot = DB::selectOne("show global variables like 'innodb_snapshot_isolation'");

        return [
            'version' => (string) $row['version'],
            'isolation' => (string) $row['isolation'],
            'innodb_snapshot_isolation' => $snapshot === null ? null : (string) ((array) $snapshot)['Value'],
        ];
    }

    /** @param  array<string, int>  $extra */
    private function worker(Workspace $workspace, ?ClientInvoice $invoice, string $mode, ?string $paused, ?string $release, array $extra = []): Process
    {
        $input = base64_encode(json_encode([
            ...$extra,
            'connection' => config('database.connections.'.DB::getDefaultConnection()),
            'workspace' => $workspace->id,
            'invoice' => $invoice?->id,
            'mode' => $mode,
            'paused' => $paused,
            'release' => $release,
        ], JSON_THROW_ON_ERROR));

        return new Process(
            [PHP_BINARY, base_path('tests/Fixtures/Billing/credit-spend-race-worker.php'), $input],
            base_path(),
            ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array'],
            null,
            90,
        );
    }

    private function awaitFile(Process $process, string $path): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($path) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10_000);
        }

        $this->assertFileExists($path, $process->getOutput().$process->getErrorOutput());
    }

    /** @return array<string, mixed> */
    private function workerResult(Process $process): array
    {
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
