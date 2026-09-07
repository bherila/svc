<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * Correcting the day a payment arrived, and nothing else about it.
 *
 * There is deliberately no payment-edit path here: a payment is corrected by
 * transitioning its status or its refunded amount, so history is preserved
 * rather than rewritten. A mistyped date is not a money correction - it moves
 * no amount and cannot move an invoice's balance - and the only remedy for one
 * before this was to cancel the payment and record it again, which invents a
 * cancellation that never happened and leaves it in the client's history.
 *
 * These pin the narrowness: one column, the same bounds as the way in, and the
 * workspace boundary that every tenant-owned write is held to.
 */
final class PaymentDateCorrectionTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Route::has('svc.billing.invoices.payments.received-on')) {
            require base_path('routes/billing.php');
        }
        Date::setTestNow(CarbonImmutable::parse('2026-08-29 12:00:00 UTC'));
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_a_correction_moves_the_date_and_leaves_the_money_alone(): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();
        $service = app(InvoiceLifecycleService::class);

        $corrected = $service->setPaymentReceivedOn($payment, '2026-08-18', $workspace);

        $this->assertSame('2026-08-18', $corrected->received_on?->toDateString());
        $this->assertSame($payment->amount, $corrected->amount);
        $this->assertSame($payment->currency, $corrected->currency);
        $this->assertSame($payment->method, $corrected->method);
        $this->assertSame($payment->status, $corrected->status);
        $this->assertSame($payment->refunded_amount, $corrected->refunded_amount);

        // Recorded the way the comparable corrections are, carrying the date it
        // replaced: the history says what was changed rather than only that the
        // row now reads differently.
        $activity = ClientCompanyActivity::query()
            ->where('action', 'invoice.payment_date_corrected')
            ->sole();
        $this->assertSame('2026-08-25', $activity->payload['previous_received_on'] ?? null);
        $this->assertSame('2026-08-18', $activity->payload['received_on'] ?? null);
        $this->assertSame($payment->amount, $activity->payload['amount'] ?? null);
    }

    /** Correcting to the date already recorded changes nothing and records nothing. */
    public function test_a_correction_to_the_recorded_date_is_a_no_op(): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();

        app(InvoiceLifecycleService::class)->setPaymentReceivedOn($payment, '2026-08-25', $workspace);

        $this->assertSame(
            0,
            ClientCompanyActivity::query()->where('action', 'invoice.payment_date_corrected')->count(),
        );
    }

    /**
     * The same window as the way in.
     *
     * A correction is not a way around the bound: an operator who can type a
     * mistaken date into the recording form can type the same one here.
     */
    public function test_a_correction_is_bounded_by_the_same_window(): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();
        $service = app(InvoiceLifecycleService::class);

        foreach ([
            '2026-08-30' => 'A payment cannot be dated after 2026-08-29',
            '2015-08-29' => 'A payment cannot be dated before 2024-08-29',
            '2026-02-31' => 'must be a real calendar date',
        ] as $refused => $expected) {
            try {
                $service->setPaymentReceivedOn($payment, (string) $refused, $workspace);
                $this->fail('The correction accepted "'.$refused.'".');
            } catch (DomainException $exception) {
                $this->assertStringContainsString($expected, $exception->getMessage());
            }
        }

        $this->assertSame('2026-08-25', $payment->fresh()?->received_on?->toDateString());
    }

    /**
     * The HTTP door validates one field and writes one column.
     *
     * Money sent alongside is not validated, not read and not written: the
     * request names `received_on` and nothing else, and the service takes a
     * date rather than an array of attributes.
     */
    public function test_the_route_corrects_the_date_and_ignores_everything_sent_beside_it(): void
    {
        [$owner, $workspace, $invoice, $payment] = $this->recordedPayment();

        $this->actingAs($owner)
            ->postJson($this->correctionUrl($workspace, $invoice, $payment), [
                'received_on' => '2026-08-18',
                'amount' => 999999,
                'status' => 'refunded',
                'refunded_amount' => 999999,
                'method' => 'cash',
            ])
            ->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame('2026-08-18', $fresh?->received_on?->toDateString());
        $this->assertSame(1000, $fresh?->amount);
        $this->assertSame(0, $fresh?->refunded_amount);
        $this->assertSame('succeeded', $fresh?->status);
        $this->assertSame('wire', $fresh?->method);
    }

    public function test_the_route_refuses_a_date_that_is_not_a_calendar_day(): void
    {
        [$owner, $workspace, $invoice, $payment] = $this->recordedPayment();

        $this->actingAs($owner)
            ->postJson($this->correctionUrl($workspace, $invoice, $payment), ['received_on' => '08/18/2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('received_on');

        $this->assertSame('2026-08-25', $payment->fresh()?->received_on?->toDateString());
    }

    /**
     * The new write is workspace-scoped, at the service and over the web.
     *
     * A payment binds by a public id unique across every workspace, so passing
     * the gate on a workspace is not passing a check on the payment reached
     * through it.
     */
    public function test_a_correction_cannot_cross_a_workspace_boundary(): void
    {
        [$owner, $workspace, $invoice, $payment] = $this->recordedPayment('Alpha');
        [, $otherWorkspace, $otherInvoice, $otherPayment] = $this->recordedPayment('Beta');
        $service = app(InvoiceLifecycleService::class);

        // The service refuses to find another workspace's payment at all.
        try {
            $service->setPaymentReceivedOn($otherPayment, '2026-08-18', $workspace);
            $this->fail('A payment was corrected from outside its workspace.');
        } catch (ModelNotFoundException) {
            $this->assertSame('2026-08-25', $otherPayment->fresh()?->received_on?->toDateString());
        }

        // Their own workspace and invoice in the URL, another workspace's
        // payment in it.
        $this->actingAs($owner)
            ->postJson(
                "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/payments/{$otherPayment->public_id}/received-on",
                ['received_on' => '2026-08-18'],
            )
            ->assertNotFound();

        // The other workspace in the URL, where they are not a member.
        $this->actingAs($owner)
            ->postJson($this->correctionUrl($otherWorkspace, $otherInvoice, $otherPayment), ['received_on' => '2026-08-18'])
            ->assertForbidden();

        $this->assertSame('2026-08-25', $otherPayment->fresh()?->received_on?->toDateString());
        $this->assertSame('2026-08-25', $payment->fresh()?->received_on?->toDateString());
    }

    /**
     * Every tenant-owned query in the correction path names a workspace.
     *
     * The rule AGENTS.md states, asserted over the whole path rather than over
     * one table. Two findings arrived here one relation apart - the invoice,
     * reached through a `belongsTo` on `client_invoice_id` alone, and then the
     * company behind it, reached through `client_company_id` alone - and the
     * first version of this test watched only `client_invoices`, so it went on
     * passing while the next dereference walked straight past it. A guard that
     * enumerates one table is a guard against one bug.
     *
     * So this is closed-world: every table the path touches must either carry a
     * workspace in the SQL or be named below as one that cannot be owned by a
     * workspace. A new relation read is a failure by default rather than a
     * silence.
     *
     * Asserted on the shape of the SQL and not on the result, because the
     * outcome of these reads was already correct - a scoped lock or the
     * activity recorder refused the mismatch afterwards. What was wrong is that
     * a foreign tenant's row was selected and materialised on the way there,
     * which no assertion about the result can see.
     *
     * Matched with the quoting stripped, because the identifier quoting is the
     * driver's business: SQLite writes `"client_invoices"` and MariaDB writes
     * backticks, and this suite runs SQLite locally while only the MariaDB job
     * in CI sees the other. A literal `from "client_invoices"` matches nothing
     * there, the captured list comes back empty, and the assertion passes or
     * fails for a reason that has nothing to do with tenancy.
     */
    public function test_the_correction_issues_no_tenant_owned_query_without_a_workspace(): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();

        /** @var array<string, list<string>> $byTable */
        $byTable = [];
        DB::listen(function (QueryExecuted $query) use (&$byTable): void {
            $sql = $this->withoutIdentifierQuoting($query->sql);
            foreach ($this->tablesIn($sql) as $table) {
                $byTable[$table][] = $sql;
            }
        });

        app(InvoiceLifecycleService::class)->setPaymentReceivedOn($payment, '2026-08-18', $workspace);

        // The path really did read the two relations the findings were about,
        // so an empty capture cannot be mistaken for a clean one.
        $this->assertArrayHasKey('client_invoices', $byTable);
        $this->assertArrayHasKey('client_companies', $byTable);

        foreach ($byTable as $table => $statements) {
            if (in_array($table, self::TABLES_WITHOUT_AN_OWNING_WORKSPACE, true)) {
                continue;
            }

            foreach ($statements as $sql) {
                $this->assertStringContainsString(
                    'workspace_id',
                    $sql,
                    "A tenant-owned query on {$table} carried no workspace: {$sql}",
                );
            }
        }
    }

    /**
     * The whole request, not the service inside it.
     *
     * Three rounds of review found this defect three times, one layer apart
     * each time: the service's invoice read, then the company behind it, then
     * the route-model binding above both. A guard that watches one layer keeps
     * passing while the defect moves to the next, so this one starts at the
     * request.
     *
     * Route-model binding is the layer the service-level guard structurally
     * cannot see. Laravel resolves a bound parameter by its route key *before*
     * the controller runs, and both of these bind by a `public_id` unique
     * across every workspace - so naming another tenant's payment selected and
     * materialised it, and the check that followed could refuse the write but
     * not un-read the row.
     *
     * Same closed-world rule as the service guard, over a real HTTP request:
     * every table the request touches must carry a workspace or be named as one
     * that cannot have an owning workspace.
     */
    public function test_the_correction_request_issues_no_tenant_owned_query_without_a_workspace(): void
    {
        [$owner, $workspace, $invoice, $payment] = $this->recordedPayment();

        /** @var array<string, list<string>> $byTable */
        $byTable = [];
        DB::listen(function (QueryExecuted $query) use (&$byTable): void {
            $sql = $this->withoutIdentifierQuoting($query->sql);
            foreach ($this->tablesIn($sql) as $table) {
                $byTable[$table][] = $sql;
            }
        });

        $this->actingAs($owner)
            ->postJson($this->correctionUrl($workspace, $invoice, $payment), ['received_on' => '2026-08-18'])
            ->assertOk();

        // The binding layer really was exercised, so an empty capture cannot be
        // mistaken for a clean one: both records are named in the URL by a
        // public id and both have to be found before anything is written.
        $this->assertArrayHasKey('client_invoices', $byTable);
        $this->assertArrayHasKey('client_invoice_payments', $byTable);

        foreach ($byTable as $table => $statements) {
            if (in_array($table, self::TABLES_WITHOUT_AN_OWNING_WORKSPACE, true)) {
                continue;
            }

            foreach ($statements as $sql) {
                $this->assertStringContainsString(
                    'workspace_id',
                    $sql,
                    "A tenant-owned query in the correction request carried no workspace: {$sql}",
                );
            }
        }
    }

    /**
     * Every payment path reads the company scoped, not just the correction.
     *
     * The unscoped company read was found in the correction and fixed in
     * `recordPaymentActivity()`, which all four payment paths record through -
     * so the fix is shared and this is what says so. Narrower than the
     * closed-world guard above on purpose: `applyPayment()` and its siblings
     * recompute the invoice, and `refreshStatus()` reads a payment set through
     * a relation that carries no workspace of its own. That is a real and
     * pre-existing shape, older than this branch and in the middle of the money
     * arithmetic, and widening this assertion to cover it would make the test
     * about a change nobody here is making.
     */
    public function test_every_payment_path_reads_the_company_with_a_workspace(): void
    {
        [, $workspace, $invoice, $payment] = $this->recordedPayment();
        $service = app(InvoiceLifecycleService::class);

        /** @var list<string> $companyReads */
        $companyReads = [];
        DB::listen(function (QueryExecuted $query) use (&$companyReads): void {
            $sql = $this->withoutIdentifierQuoting($query->sql);
            if (in_array('client_companies', $this->tablesIn($sql), true)) {
                $companyReads[] = $sql;
            }
        });

        $service->applyPayment($invoice, [
            'amount' => 2000, 'currency' => 'USD', 'method' => 'ach', 'received_on' => '2026-08-26',
        ], $workspace);
        $service->setPaymentReceivedOn($payment, '2026-08-18', $workspace);
        $service->setRefundedAmount($payment->refresh(), 500, $workspace);
        $service->setPaymentStatus($payment->refresh(), 'canceled', $workspace);

        $this->assertNotSame([], $companyReads, 'The payment paths must read the company they record against.');
        foreach ($companyReads as $sql) {
            $this->assertStringContainsString(
                'workspace_id',
                $sql,
                'A payment path read a client company with no workspace: '.$sql,
            );
        }
    }

    /**
     * The guard above reads both drivers' SQL, which it cannot prove in place.
     *
     * This suite runs SQLite and only the MariaDB job in CI sees the other, so
     * a driver-sensitive assertion is invisible here by construction - which is
     * exactly how the first version of the test above reached CI matching the
     * literal `from "client_invoices"` and failing on MariaDB with "the
     * correction must read the invoice it records against", an empty capture
     * reported as a missing read.
     *
     * So the part that depends on the driver is separated out and checked
     * against both spellings directly, on whichever engine this happens to run.
     */
    public function test_the_query_shape_guard_reads_either_drivers_quoting(): void
    {
        $sqlite = 'select * from "client_invoices" where "client_invoices"."id" = ? and "workspace_id" = ? limit 1';
        $mariadb = 'select * from `client_invoices` where `client_invoices`.`id` = ? and `workspace_id` = ? limit 1';

        foreach ([$sqlite, $mariadb] as $statement) {
            $unquoted = $this->withoutIdentifierQuoting($statement);

            $this->assertSame(['client_invoices'], $this->tablesIn($unquoted));
            $this->assertStringContainsString('workspace_id', $unquoted);
        }

        // And a statement that genuinely lacks a workspace is still seen to.
        $this->assertStringNotContainsString(
            'workspace_id',
            $this->withoutIdentifierQuoting('select * from `client_companies` where `id` = ? limit 1'),
        );
    }

    /**
     * A payment naming an invoice in another workspace is not corrected.
     *
     * Unstorable since #113's composite tenant keys, and reachable in a
     * database migrated from before them - which is the population the scoped
     * query is the second line of defence for. The refusal is a "not found"
     * rather than an explanation, because an invoice that is not this tenant's
     * is not one to describe to them.
     */
    public function test_a_payment_naming_a_foreign_invoice_cannot_be_corrected(): void
    {
        [, $workspace, , $payment] = $this->recordedPayment('Alpha');
        [, , $foreignInvoice] = $this->recordedPayment('Beta');

        $this->writingLegacyCrossTenantRows(
            fn () => $payment->forceFill(['client_invoice_id' => $foreignInvoice->id])->save(),
        );

        try {
            app(InvoiceLifecycleService::class)->setPaymentReceivedOn($payment->refresh(), '2026-08-18', $workspace);
            $this->fail('A payment was corrected against an invoice in another workspace.');
        } catch (ModelNotFoundException) {
            $this->assertSame('2026-08-25', $payment->fresh()?->received_on?->toDateString());
        }
    }

    /**
     * Tables a workspace cannot own, and why each is here.
     *
     * `workspaces` is the tenant itself: a workspace row is read by its own
     * key, and asking it to carry a `workspace_id` is asking it to be its own
     * parent. `users` is the other thing a workspace does not own - a person
     * belongs to as many workspaces as they are a member of, which is what
     * `workspace_memberships` records, and that table does carry one.
     *
     * Nothing else is exempt. A table added to this list is a claim that has to
     * be argued for, which is the point of the list being here rather than of
     * the assertion being narrower.
     */
    private const TABLES_WITHOUT_AN_OWNING_WORKSPACE = ['workspaces', 'users'];

    /** Identifier quoting is the driver's business: SQLite quotes, MariaDB backticks. */
    private function withoutIdentifierQuoting(string $sql): string
    {
        return str_replace(['`', '"', '[', ']'], '', $sql);
    }

    /**
     * The tables one statement reads or writes, from unquoted SQL.
     *
     * @return list<string>
     */
    private function tablesIn(string $sql): array
    {
        $matches = [];
        preg_match_all('/\b(?:from|into|update|join)\s+([a-z0-9_]+)/i', $sql, $matches);

        /** @var list<string> $tables */
        $tables = array_values(array_unique($matches[1]));

        return $tables;
    }

    private function correctionUrl(Workspace $workspace, ClientInvoice $invoice, ClientInvoicePayment $payment): string
    {
        return "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/payments/{$payment->public_id}/received-on";
    }

    /** @return array{0:User,1:Workspace,2:ClientInvoice,3:ClientInvoicePayment} */
    private function recordedPayment(string $name = 'Correction Workspace'): array
    {
        $owner = User::factory()->create(['email' => 'correction-'.str()->random(6).'@synthetic.test']);
        $workspace = Workspace::query()->create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.str()->random(5),
        ]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Client',
            'slug' => 'synthetic-client-'.$workspace->id,
        ]);

        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->issue($service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-CORRECT-'.str()->upper(str()->random(8)),
            'currency' => 'USD',
        ], [[
            'type' => 'service',
            'description' => 'Synthetic service',
            'quantity' => '2',
            'unit_amount' => 5000,
            'tax_amount' => 0,
            'sort_order' => 1,
        ]]), $workspace);

        $payment = $service->applyPayment($invoice, [
            'amount' => 1000,
            'currency' => 'USD',
            'method' => 'wire',
            'received_on' => '2026-08-25',
        ], $workspace);

        return [$owner, $workspace, $invoice, $payment];
    }
}
