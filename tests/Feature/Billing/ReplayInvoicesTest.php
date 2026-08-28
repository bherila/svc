<?php

namespace Tests\Feature\Billing;

use App\Console\Commands\Billing\ReplayInvoicesCommand;
use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The replay harness has to be trustworthy in two opposite directions: it must
 * notice when the engine no longer reproduces history, and it must never write
 * anything while finding out. A harness that silently passes is useless; one
 * that mutates production data to run is dangerous.
 */
final class ReplayInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Replay', 'slug' => 'replay']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Replay Client', 'slug' => 'replay-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Replay Project',
        ]);
        $this->user = User::factory()->create();
    }

    public function test_it_requires_a_workspace(): void
    {
        $this->artisan('svc:billing:replay')->assertFailed();
        $this->artisan('svc:billing:replay', ['--workspace' => 'nope'])->assertFailed();
    }

    /**
     * Invoices the current engine produced are, by definition, reproducible by
     * the current engine. This is the control: if it fails, the harness is
     * comparing the wrong things.
     */
    public function test_invoices_the_engine_produced_replay_to_the_cent(): void
    {
        $this->generatedHistory();

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])
            ->expectsOutputToContain('money identical')
            ->assertSuccessful();
    }

    /**
     * The harness must fail when history and the engine disagree. Editing a
     * stored total stands in for the engine having drifted.
     */
    public function test_it_fails_when_a_stored_invoice_no_longer_reproduces(): void
    {
        $invoice = $this->generatedHistory();

        // History says this invoice was worth 1.00 more than the engine now says.
        $invoice->forceFill(['total_amount' => (int) $invoice->total_amount + 100])->save();

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])
            ->assertFailed();
    }

    /**
     * The command claims money is exact to the cent. Comparing totals per type
     * cannot deliver that: a line repriced with a compensating quantity reaches
     * the same total, the same per-type total and the same line count, so every
     * aggregate agrees while the client was charged for something else.
     */
    public function test_a_repriced_line_is_not_reported_as_an_exact_reproduction(): void
    {
        $invoice = $this->generatedHistory();

        /** @var ClientInvoiceLine $line */
        $line = $invoice->lines()->orderByDesc('total_amount')->firstOrFail();
        $unit = (int) $line->unit_amount;
        $this->assertSame(0, $unit % 2, 'This fixture needs an even unit amount to halve.');

        // Half the price, twice as many. Total unchanged, so nothing an
        // aggregate looks at moves.
        $line->forceFill([
            'unit_amount' => intdiv($unit, 2),
            'quantity' => (float) $line->quantity * 2,
        ])->save();

        // tempnam() creates the file it names, so use that path rather than a
        // suffixed sibling - otherwise every run leaves the original behind.
        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->assertFailed();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $verdicts = array_column($detail['comparisons'], 'verdict', 'invoice_number');

        // Every aggregate agrees on this invoice, so the old comparison called
        // it an exact reproduction. The individual lines are what disagree.
        $this->assertArrayHasKey((string) $invoice->invoice_number, $verdicts);
        // A repriced line is a money difference, not an arrangement: the client
        // was charged for something they were not charged for. It gates.
        $this->assertSame('money_differs', $verdicts[(string) $invoice->invoice_number]);
    }

    /**
     * The deliberate corrections say which line types a period should carry.
     * They say nothing about what a line of that type costs, so a repriced line
     * must not be waived by the correction that explains why its type moved.
     */
    public function test_a_repriced_line_is_not_waived_by_a_correction_that_covers_its_type(): void
    {
        $this->generatedHistory();

        // prior_month_retainer is one of the capacity-dependent types, so this
        // is the invoice a correction could otherwise excuse. Only the unit
        // price moves: the invoice total is untouched, so nothing above the
        // line level has anything to notice.
        /** @var ClientInvoiceLine $line */
        $line = ClientInvoiceLine::query()->where('type', 'prior_month_retainer')->firstOrFail();
        $line->forceFill(['unit_amount' => (int) $line->unit_amount + 5000])->save();

        $invoice = $line->invoice()->firstOrFail();
        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->assertFailed();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $rows = array_column($detail['comparisons'], null, 'invoice_number');
        $row = $rows[(string) $invoice->invoice_number] ?? null;

        $this->assertNotNull($row);
        $this->assertSame('money_differs', $row['verdict']);
        $this->assertSame(0, $row['money_delta']);
        $this->assertNull($row['explained_by'] ?? null);
    }

    /**
     * A line the engine no longer produces is a composition change, and
     * composition is what the four deliberate corrections exist to explain. It
     * must not be mistaken for a repricing, which is never explainable.
     */
    public function test_a_line_the_engine_no_longer_produces_is_not_read_as_a_repricing(): void
    {
        $this->generatedHistory();

        /** @var ClientInvoiceLine $line */
        $line = ClientInvoiceLine::query()->where('type', 'prior_month_retainer')->firstOrFail();

        // History carried this charge twice; the engine produces it once. Every
        // amount is identical, so nothing about what the client pays moved.
        $duplicate = $line->replicate();
        $duplicate->public_id = (string) Str::uuid();
        $duplicate->sort_order = (int) $line->sort_order + 1;
        $duplicate->save();

        $invoice = $line->invoice()->firstOrFail();
        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->run();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $rows = array_column($detail['comparisons'], null, 'invoice_number');
        $row = $rows[(string) $invoice->invoice_number] ?? null;

        $this->assertNotNull($row);
        // Comparing prices as a multiset would call one-of-these-instead-of-two
        // a money difference, and no correction could ever explain it.
        $this->assertSame('composition_differs', $row['verdict']);
    }

    /**
     * The composer writes amounts into its own wording, so a repriced line
     * arrives with a different description. If the description identified the
     * charge, the repricing would read as one line removed and another added -
     * composition, which a correction is allowed to waive.
     */
    public function test_a_repricing_hidden_by_its_own_description_still_gates(): void
    {
        $this->generatedHistory();

        /** @var ClientInvoiceLine $line */
        $line = ClientInvoiceLine::query()->where('type', 'prior_month_retainer')->firstOrFail();
        $this->assertMatchesRegularExpression('/\d/', (string) $line->description, 'This fixture needs an amount-bearing description.');

        // History carried a different quantity, and its description says so -
        // exactly what the composer would have written for that quantity.
        $line->forceFill([
            'quantity' => '5.0000',
            'description' => (string) preg_replace('/\d+:\d+/', '99:00', (string) $line->description),
        ])->save();

        $invoice = $line->invoice()->firstOrFail();
        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->assertFailed();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $rows = array_column($detail['comparisons'], null, 'invoice_number');
        $row = $rows[(string) $invoice->invoice_number] ?? null;

        $this->assertNotNull($row);
        $this->assertSame('money_differs', $row['verdict']);
        $this->assertNull($row['explained_by'] ?? null);
    }

    /**
     * Charges are paired by what their wording says they are for, with only the
     * amounts a composer writes into that wording removed. Removing every digit
     * instead would make "Phase 1" and "Phase 2" one charge - and two charges
     * that exchange prices leave every total, count and distinct amount
     * unchanged, so pairing is the only thing that can see the swap.
     */
    public function test_charge_wording_keeps_the_numbers_that_are_not_amounts(): void
    {
        $withoutAmounts = new ReflectionMethod(ReplayInvoicesCommand::class, 'withoutAmounts');

        $phaseOne = (string) $withoutAmounts->invoke(null, 'Milestone: Phase 1');
        $phaseTwo = (string) $withoutAmounts->invoke(null, 'Milestone: Phase 2');
        $this->assertNotSame($phaseOne, $phaseTwo);

        // A milestone title is user text appended whole, so a figure in it
        // names the charge rather than pricing it. Nothing outside the
        // descriptions the billing services generate is normalised - including
        // a title that happens to end in a parenthetical of its own.
        $this->assertNotSame(
            (string) $withoutAmounts->invoke(null, 'Milestone: Package $100'),
            (string) $withoutAmounts->invoke(null, 'Milestone: Package $200'),
        );
        $this->assertNotSame(
            (string) $withoutAmounts->invoke(null, 'Milestone: Package ($100)'),
            (string) $withoutAmounts->invoke(null, 'Milestone: Package ($200)'),
        );

        // A worker's name is user text too, and it sits before the generated
        // suffix rather than after it. Only the last group is the amount.
        $this->assertNotSame(
            (string) $withoutAmounts->invoke(null, 'Subcontractor: Alex (Senior) (1:00 @ 60.00 USD/hr)'),
            (string) $withoutAmounts->invoke(null, 'Subcontractor: Alex (Junior) (1:00 @ 60.00 USD/hr)'),
        );
        $this->assertSame(
            (string) $withoutAmounts->invoke(null, 'Subcontractor: Alex (Senior) (1:00 @ 60.00 USD/hr)'),
            (string) $withoutAmounts->invoke(null, 'Subcontractor: Alex (Senior) (2:00 @ 90.00 USD/hr)'),
        );

        // And a generated line keeps everything that names its cycle while
        // losing the hours it was priced from.
        $this->assertSame(
            (string) $withoutAmounts->invoke(null, 'Monthly Retainer (10 hours) - Feb 1, 2024 through Feb 29, 2024'),
            (string) $withoutAmounts->invoke(null, 'Monthly Retainer (99 hours) - Feb 1, 2024 through Feb 29, 2024'),
        );
        $this->assertNotSame(
            (string) $withoutAmounts->invoke(null, 'Monthly Retainer (10 hours) - Feb 1, 2024 through Feb 29, 2024'),
            (string) $withoutAmounts->invoke(null, 'Monthly Retainer (10 hours) - Mar 1, 2024 through Mar 31, 2024'),
        );

        // The amounts, though, have to go: the composer writes them from the
        // very price and quantity the comparison exists to detect.
        $this->assertSame(
            (string) $withoutAmounts->invoke(null, 'Deferred work items billed on agreement termination (12:30 @ 150.00 USD/hr)'),
            (string) $withoutAmounts->invoke(null, 'Deferred work items billed on agreement termination (99:00 @ 9.00 USD/hr)'),
        );
        $this->assertSame(
            (string) $withoutAmounts->invoke(null, 'Work items applied to retainer (15:00 applied to February 2024 pool)'),
            (string) $withoutAmounts->invoke(null, 'Work items applied to retainer (2:30 applied to February 2024 pool)'),
        );
    }

    /**
     * Two charges exchanging prices leaves the invoice total, the line count
     * and the set of distinct amounts all unchanged. Nothing that looks at the
     * invoice as a whole can see it; only pairing each charge to its own
     * counterpart can.
     */
    public function test_two_charges_that_swap_prices_are_not_a_match(): void
    {
        $this->generatedHistory();

        $invoiceId = ClientInvoiceLine::query()->where('type', 'prior_month_retainer')->value('client_invoice_id');
        $lines = ClientInvoiceLine::query()->where('client_invoice_id', $invoiceId)->orderBy('sort_order')->get();
        $this->assertGreaterThanOrEqual(2, $lines->count(), 'This fixture needs two lines on one invoice.');

        /** @var ClientInvoiceLine $a */
        $a = $lines->firstOrFail();
        /** @var ClientInvoiceLine $b */
        $b = $lines->last();
        $this->assertNotSame((int) $a->total_amount, (int) $b->total_amount, 'The two lines must differ for a swap to mean anything.');

        $carry = ['unit_amount' => (int) $a->unit_amount, 'quantity' => (string) $a->quantity, 'total_amount' => (int) $a->total_amount];
        $a->forceFill(['unit_amount' => (int) $b->unit_amount, 'quantity' => (string) $b->quantity, 'total_amount' => (int) $b->total_amount])->save();
        $b->forceFill($carry)->save();

        $this->assertSame('money_differs', $this->verdictFor($a->invoice()->firstOrFail()->invoice_number));
    }

    /**
     * A charge whose wording changes along with its price has no counterpart to
     * be paired with, so pairing cannot see the repricing. What gives it away
     * is that the invoice now states an amount it did not state before.
     */
    public function test_a_repricing_that_takes_its_wording_with_it_still_gates(): void
    {
        $this->generatedHistory();

        /** @var ClientInvoiceLine $line */
        $line = ClientInvoiceLine::query()->orderByDesc('total_amount')->firstOrFail();

        // Nothing recognisable survives to pair on, and the invoice total is
        // untouched, so only the amounts themselves say anything happened.
        $line->forceFill([
            'description' => 'An entirely different charge',
            'unit_amount' => (int) $line->unit_amount + 700,
        ])->save();

        $this->assertSame('money_differs', $this->verdictFor($line->invoice()->firstOrFail()->invoice_number));
    }

    /**
     * A line the engine no longer produces, priced at an amount that appears
     * nowhere else, is still a line removed. Comparing the amounts an invoice
     * states must not read that as a repricing, or the corrections that exist
     * to remove exactly such a line could never explain one.
     */
    public function test_removing_a_uniquely_priced_line_stays_explainable(): void
    {
        $this->generatedHistory();

        /** @var ClientInvoiceLine $line */
        $line = ClientInvoiceLine::query()->where('type', 'prior_month_retainer')->firstOrFail();
        $invoiceNumber = (string) $line->invoice()->firstOrFail()->invoice_number;

        // History carried one more charge than the engine produces, at a price
        // no other line on the invoice states.
        $extra = $line->replicate();
        $extra->public_id = (string) Str::uuid();
        $extra->sort_order = (int) $line->sort_order + 1;
        $extra->unit_amount = 1234;
        $extra->quantity = '1.0000';
        $extra->total_amount = 0;
        $extra->description = 'A charge the engine no longer makes';
        $extra->save();

        // Reported, not gated: the money the client owes is unchanged and a
        // correction is allowed to account for the line being gone.
        $this->assertSame('composition_differs', $this->verdictFor($invoiceNumber));
    }

    /**
     * A charge repriced onto an amount the invoice already states changes only
     * how many times that amount appears. No total moves, no line count moves,
     * and if the wording moved with the price there is nothing to pair either -
     * one count falling while another rises is the whole signal.
     */
    public function test_a_repricing_onto_an_existing_amount_still_gates(): void
    {
        $this->generatedHistory();

        $invoiceId = ClientInvoiceLine::query()->where('type', 'prior_month_retainer')->value('client_invoice_id');
        $lines = ClientInvoiceLine::query()->where('client_invoice_id', $invoiceId)->orderBy('sort_order')->get();
        $this->assertGreaterThanOrEqual(2, $lines->count());

        /** @var ClientInvoiceLine $a */
        $a = $lines->firstOrFail();
        /** @var ClientInvoiceLine $b */
        $b = $lines->last();

        // History priced this charge exactly like the other one, and worded it
        // differently too, so pairing has no counterpart to compare against.
        $a->forceFill([
            'description' => 'A charge worded nothing like the engine words it',
            'unit_amount' => (int) $b->unit_amount,
            'quantity' => (string) $b->quantity,
            'total_amount' => (int) $a->total_amount,
        ])->save();

        $this->assertSame('money_differs', $this->verdictFor($a->invoice()->firstOrFail()->invoice_number));
    }

    /**
     * A correction that removes one charge and adds another moves money in
     * aggregate without repricing anything. The money must still be reported,
     * but the correction has to remain able to account for it - otherwise the
     * corrections this port makes on purpose can never explain their own work.
     */
    public function test_a_charge_replaced_by_another_stays_explainable(): void
    {
        $this->generatedHistory();

        /** @var ClientInvoiceLine $line */
        $line = ClientInvoiceLine::query()->where('type', 'prior_month_retainer')->firstOrFail();
        $invoiceNumber = (string) $line->invoice()->firstOrFail()->invoice_number;

        // Different wording and different amounts: nothing to pair against, so
        // this is one charge gone and another arrived rather than a repricing.
        $line->forceFill([
            'description' => 'A charge the engine replaced with a different one',
            'unit_amount' => 2500,
            'quantity' => '1.0000',
        ])->save();

        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->run();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $row = array_column($detail['comparisons'], null, 'invoice_number')[$invoiceNumber] ?? null;

        $this->assertNotNull($row);
        $this->assertFalse($row['line_repriced'], 'Nothing was repriced; a charge was replaced.');
        // Refusing attribution on any line-money movement would make
        // composition permanently unexplainable, which is the opposite of what
        // the deliberate corrections are for.
        $this->assertNotNull($row['explained_by'] ?? null);
    }

    /**
     * One worker on two projects gets one line per project, worded identically.
     * Exchanging their prices moves no total, no count, and no amount the
     * invoice states - only which project each is filed under separates them,
     * so the pairing has to see the project before it falls back to wording.
     */
    public function test_two_concurrent_charges_that_swap_prices_are_not_a_match(): void
    {
        $here = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Here',
        ]);
        $there = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'There',
        ]);

        // One worker, two projects, two different rates. The composer writes a
        // line per project, worded identically because the wording names the
        // worker rather than the project.
        foreach ([[$here, 6000], [$there, 9000]] as [$project, $rate]) {
            ClientTimeEntry::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'client_project_id' => $project->id,
                'user_id' => $this->user->id,
                'worked_on' => '2024-02-14',
                'minutes' => 60,
                'description' => 'Subcontracted work',
                'is_billable' => true,
                'is_deferred' => false,
                'status' => 'approved',
                'currency' => 'USD',
                'subcontractor_cost_amount' => $rate,
                'subcontractor_cost_currency' => 'USD',
            ]);
        }

        $this->generatedHistory();

        $subcontracted = ClientInvoiceLine::query()->where('type', 'subcontractor')->orderBy('id')->get();
        if ($subcontracted->count() < 2) {
            $this->markTestSkipped('This fixture did not produce two concurrent subcontractor lines.');
        }

        /** @var ClientInvoiceLine $a */
        $a = $subcontracted->firstOrFail();
        /** @var ClientInvoiceLine $b */
        $b = $subcontracted->last();
        // Their wording differs only by the rate it quotes, so once the amounts
        // are taken out they name the same charge - which is the whole point:
        // only the project tells these two apart.
        $withoutAmounts = new ReflectionMethod(ReplayInvoicesCommand::class, 'withoutAmounts');
        $this->assertSame(
            (string) $withoutAmounts->invoke(null, (string) $a->description),
            (string) $withoutAmounts->invoke(null, (string) $b->description),
        );
        $this->assertNotSame((int) $a->unit_amount, (int) $b->unit_amount);

        // History charged each project at the other's rate, wording and all.
        // Every total, every count and every amount the invoice states is
        // unchanged; only which project paid which rate moved.
        $carry = ['unit_amount' => (int) $a->unit_amount, 'total_amount' => (int) $a->total_amount, 'description' => (string) $a->description];
        $a->forceFill(['unit_amount' => (int) $b->unit_amount, 'total_amount' => (int) $b->total_amount, 'description' => (string) $b->description])->save();
        $b->forceFill($carry)->save();

        $this->assertSame('money_differs', $this->verdictFor($a->invoice()->firstOrFail()->invoice_number));
    }

    /**
     * A charge that moves project and changes price at the same time. Filing
     * the move under attribution must not take the repricing with it.
     */
    /**
     * Two concurrent charges that both move and exchange prices. Nothing links
     * which became which, so pairing cannot decide - and where it cannot
     * decide it must not certify, or a repricing passes as a pair of moves.
     */
    public function test_two_concurrent_charges_that_both_move_and_swap_are_not_certified(): void
    {
        $here = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Here',
        ]);
        $there = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'There',
        ]);

        foreach ([[$here, 6000], [$there, 9000]] as [$project, $rate]) {
            ClientTimeEntry::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'client_project_id' => $project->id,
                'user_id' => $this->user->id,
                'worked_on' => '2024-02-14',
                'minutes' => 60,
                'description' => 'Subcontracted work',
                'is_billable' => true,
                'is_deferred' => false,
                'status' => 'approved',
                'currency' => 'USD',
                'subcontractor_cost_amount' => $rate,
                'subcontractor_cost_currency' => 'USD',
            ]);
        }

        $this->generatedHistory();

        $subcontracted = ClientInvoiceLine::query()->where('type', 'subcontractor')->orderBy('id')->get();
        if ($subcontracted->count() < 2) {
            $this->markTestSkipped('This fixture did not produce two concurrent subcontractor lines.');
        }

        $elsewhere = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Elsewhere',
        ]);
        $beyond = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Beyond',
        ]);

        /** @var ClientInvoiceLine $a */
        $a = $subcontracted->firstOrFail();
        /** @var ClientInvoiceLine $b */
        $b = $subcontracted->last();

        // Both filed somewhere else than the engine files them, and their
        // prices exchanged. The first pass can pair neither, and under the
        // second they are one identity carrying the same two prices.
        $carry = ['unit_amount' => (int) $a->unit_amount, 'total_amount' => (int) $a->total_amount, 'description' => (string) $a->description];
        $a->forceFill(['client_project_id' => $elsewhere->id, 'unit_amount' => (int) $b->unit_amount,
            'total_amount' => (int) $b->total_amount, 'description' => (string) $b->description])->save();
        $b->forceFill(['client_project_id' => $beyond->id] + $carry)->save();

        $this->assertSame('money_differs', $this->verdictFor($a->invoice()->firstOrFail()->invoice_number));
    }

    /**
     * Which recurring item a line belongs to is filing, like its project or its
     * date. A charge that moves between items while its price changes must
     * still be recognised as the same charge repriced - moving between items is
     * exactly what the recurring-item correction is about, so letting the move
     * hide the repricing would hand it the one thing it must never waive.
     */
    public function test_a_charge_that_moves_between_recurring_items_is_not_filed_as_a_move(): void
    {
        $invoice = $this->generatedHistory();
        $agreement = ClientAgreement::query()->firstOrFail();

        $item = ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_agreement_id' => $agreement->id,
            'description' => 'Somewhere to move to',
            'cadence' => 'monthly',
            'quantity' => '1.0000',
            'amount' => 1000,
            'currency' => 'USD',
            'is_active' => false,
        ]);

        /** @var ClientInvoiceLine $line */
        $line = ClientInvoiceLine::query()->where('client_invoice_id', $invoice->id)
            ->orderByDesc('total_amount')->firstOrFail();
        $unit = (int) $line->unit_amount;
        $this->assertSame(0, $unit % 2, 'This fixture needs an even unit amount to halve.');

        // Filed under a recurring item the engine does not use, at half the
        // price and twice the quantity, so the invoice total never moves.
        $line->forceFill([
            'client_agreement_recurring_item_id' => $item->id,
            'unit_amount' => intdiv($unit, 2),
            'quantity' => (string) ((float) $line->quantity * 2),
        ])->save();

        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->run();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $row = array_column($detail['comparisons'], null, 'invoice_number')[(string) $invoice->invoice_number] ?? null;

        $this->assertNotNull($row);
        $this->assertTrue($row['line_repriced'], 'The move must not absorb the repricing.');
        $this->assertNull($row['explained_by'] ?? null);
    }

    /**
     * A charge misfiled onto a key the other side already uses. Dropping every
     * line that shares a key would take the other side's genuine line out with
     * it, and both would leave the comparison together.
     */
    public function test_a_charge_misfiled_onto_an_occupied_key_is_still_paired(): void
    {
        [$a, $b] = $this->twoConcurrentSubcontractorCharges();

        // History filed this charge under the other project - where a charge
        // already sits - and repriced it to match.
        $a->forceFill([
            'client_project_id' => $b->client_project_id,
            'unit_amount' => (int) $b->unit_amount,
            'total_amount' => (int) $b->total_amount,
            'description' => (string) $b->description,
        ])->save();

        $row = $this->comparisonFor((string) $a->invoice()->firstOrFail()->invoice_number);

        $this->assertNotNull($row);
        $this->assertTrue($row['line_repriced'], 'The collision must not take the genuine line out of the pairing.');
    }

    /**
     * A line stores its own agreement, restored per source row, so it need not
     * match the invoice's. Reattributing a charge to another agreement changes
     * nothing else about it and must not read as an exact reproduction.
     */
    public function test_a_line_reattributed_to_another_agreement_is_not_a_match(): void
    {
        $invoice = $this->generatedHistory();

        $other = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Another agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'retainer_minutes' => 0,
            'retainer_amount' => 0,
            'hourly_rate_amount' => 20000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 0,
        ]);

        ClientInvoiceLine::query()->where('client_invoice_id', $invoice->id)
            ->orderByDesc('total_amount')->firstOrFail()
            ->forceFill(['client_agreement_id' => $other->id])->save();

        $this->assertSame('composition_differs', $this->verdictFor((string) $invoice->invoice_number));
    }

    /**
     * A charge that moves onto an occupied key while keeping its own price. The
     * destination key gains a price without anything being repriced, so
     * comparing the two price sets whole calls it a repricing; matching the
     * occurrences first leaves only the move.
     */
    public function test_a_charge_that_moves_without_repricing_stays_composition(): void
    {
        [$a, $b] = $this->twoConcurrentSubcontractorCharges();

        // Filed under the other project, at its own price. Every amount the
        // invoice states is unchanged; only where one charge sits moved.
        $a->forceFill(['client_project_id' => $b->client_project_id])->save();

        $row = $this->comparisonFor((string) $a->invoice()->firstOrFail()->invoice_number);

        $this->assertNotNull($row);
        $this->assertFalse($row['line_repriced'], 'Nothing was repriced; a charge moved.');
        $this->assertSame('composition_differs', $row['verdict']);
    }

    /**
     * Two charges collapsed onto one key and both repriced. The key is shared,
     * and each side is left holding a price the other does not state - which is
     * the first pass's whole question, asked of two charges at once.
     */
    public function test_two_charges_filed_onto_one_key_and_repriced_are_caught(): void
    {
        [$a, $b] = $this->twoConcurrentSubcontractorCharges();

        // Both filed where the second sits, and both repriced to figures the
        // engine never produces.
        $a->forceFill(['client_project_id' => $b->client_project_id, 'unit_amount' => 1111, 'total_amount' => 1111])->save();
        $b->forceFill(['unit_amount' => 2222, 'total_amount' => 2222])->save();

        $row = $this->comparisonFor((string) $a->invoice()->firstOrFail()->invoice_number);

        $this->assertNotNull($row);
        $this->assertTrue($row['line_repriced']);
    }

    /**
     * Two charges under one filing at one price, one of which the engine
     * prices differently. The price is still stated on both sides and both
     * charges still share their filing, so every pairing pass matches them off
     * against each other - only counting the occurrences shows that one of the
     * two left that price.
     */
    public function test_one_of_two_identically_priced_charges_being_repriced_is_caught(): void
    {
        $project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'One project',
        ]);

        // One worker, one project, two rates - so the composer writes two lines
        // that differ only in the rate their wording quotes, which is exactly
        // what identity normalises away.
        foreach ([6000, 9000] as $rate) {
            ClientTimeEntry::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'client_project_id' => $project->id,
                'user_id' => $this->user->id,
                'worked_on' => '2024-02-14',
                'minutes' => 60,
                'description' => 'Subcontracted work',
                'is_billable' => true,
                'is_deferred' => false,
                'status' => 'approved',
                'currency' => 'USD',
                'subcontractor_cost_amount' => $rate,
                'subcontractor_cost_currency' => 'USD',
            ]);
        }

        $this->generatedHistory();

        $lines = ClientInvoiceLine::query()->where('type', 'subcontractor')->orderBy('id')->get();
        if ($lines->count() < 2) {
            $this->markTestSkipped('This fixture did not produce two charges under one filing.');
        }

        /** @var ClientInvoiceLine $a */
        $a = $lines->firstOrFail();
        /** @var ClientInvoiceLine $b */
        $b = $lines->last();

        // History charged both at the second's price.
        $a->forceFill([
            'unit_amount' => (int) $b->unit_amount,
            'total_amount' => (int) $b->total_amount,
            'description' => (string) $b->description,
        ])->save();

        $row = $this->comparisonFor((string) $a->invoice()->firstOrFail()->invoice_number);

        $this->assertNotNull($row);
        $this->assertTrue($row['line_repriced']);
    }

    public function test_a_charge_that_moves_and_reprices_is_not_filed_as_a_move(): void
    {
        $this->generatedHistory();

        /** @var ClientInvoiceLine $line */
        $line = ClientInvoiceLine::query()->orderByDesc('total_amount')->firstOrFail();
        $project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Somewhere else',
        ]);

        $unit = (int) $line->unit_amount;
        $this->assertSame(0, $unit % 2, 'This fixture needs an even unit amount to halve.');

        // Half the price at twice the quantity, so the invoice total is
        // untouched, and filed under a different project at the same time.
        $line->forceFill([
            'client_project_id' => $project->id,
            'unit_amount' => intdiv($unit, 2),
            'quantity' => (string) ((float) $line->quantity * 2),
        ])->save();

        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->run();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $number = (string) $line->invoice()->firstOrFail()->invoice_number;
        $row = array_column($detail['comparisons'], null, 'invoice_number')[$number] ?? null;

        $this->assertNotNull($row);
        $this->assertSame('money_differs', $row['verdict']);
        // Repriced, not merely moved. The move puts it beyond the pairing that
        // knows where a charge is filed, so only pairing on what the charge is
        // can still recognise it as the same charge at a different price.
        $this->assertTrue($row['line_repriced']);
    }

    /**
     * The safety property. The command deletes and regenerates every invoice to
     * do its work, so the only thing standing between it and production data is
     * the unconditional rollback.
     */
    public function test_it_leaves_the_database_exactly_as_it_found_it(): void
    {
        $invoice = $this->generatedHistory();
        $invoice->forceFill(['total_amount' => (int) $invoice->total_amount + 100])->save();

        $before = $this->fingerprint();

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])->assertFailed();

        $this->assertSame($before, $this->fingerprint(), 'The replay must not change a single row');
    }

    /**
     * An operator-typed invoice has no generator that would reproduce it, so
     * counting it as a divergence would make every real run fail.
     */
    public function test_ad_hoc_invoices_are_set_aside_rather_than_failed(): void
    {
        $this->generatedHistory();

        ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'SVC-ADHOC',
            'currency' => 'USD',
            'status' => 'issued',
            'invoice_kind' => 'ad_hoc',
            'service_period_start' => '2023-01-01',
            'service_period_end' => '2023-01-31',
            'subtotal_amount' => 50000,
            'tax_amount' => 0,
            'total_amount' => 50000,
        ]);
        ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => (int) ClientInvoice::query()->where('invoice_number', 'SVC-ADHOC')->value('id'),
            'type' => 'additional_hours', 'description' => 'One-off', 'quantity' => '1',
            'unit_amount' => 50000, 'tax_amount' => 0, 'total_amount' => 50000, 'sort_order' => 0,
        ]);

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])
            ->expectsOutputToContain('ad-hoc')
            ->assertSuccessful();
    }

    /**
     * The harness must not invent the divergences it reports.
     *
     * `OverpaymentCreditService` derives credit from payment rows measured
     * against `total_amount`. Blanking the totals for regeneration while
     * leaving the payments made every settled invoice in history look overpaid
     * by its full amount, and the regenerated invoices then drew on a credit
     * pool that had never existed.
     */
    public function test_a_paid_invoice_does_not_become_credit_for_the_invoice_replacing_it(): void
    {
        $invoice = $this->generatedHistory();
        $invoice->forceFill([
            'status' => 'paid',
            'paid_amount' => $invoice->total_amount,
            'balance_amount' => 0,
        ])->save();

        DB::table('client_invoice_payments')->insert([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $invoice->id,
            'public_id' => (string) Str::uuid(),
            'amount' => (int) $invoice->total_amount,
            'currency' => 'USD',
            'status' => 'succeeded',
            'received_on' => '2024-02-05',
            'method' => 'bank_transfer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])
            ->doesntExpectOutputToContain('credit')
            ->assertSuccessful();
    }

    /**
     * An ad-hoc invoice is excluded from the comparison and never regenerated,
     * so releasing its claims handed already-billed work to the cadence
     * generator and reported the second charge as a divergence.
     */
    public function test_an_ad_hoc_invoices_milestone_claim_survives_the_replay(): void
    {
        $this->generatedHistory();

        $adHoc = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'SVC-ADHOC-2',
            'currency' => 'USD',
            'status' => 'issued',
            'invoice_kind' => 'ad_hoc',
            'service_period_start' => '2024-01-01',
            'service_period_end' => '2024-01-31',
            'subtotal_amount' => 50000,
            'tax_amount' => 0,
            'total_amount' => 50000,
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $adHoc->id,
            'type' => 'milestone', 'description' => 'Billed ad hoc', 'quantity' => '1',
            'unit_amount' => 50000, 'tax_amount' => 0, 'total_amount' => 50000, 'sort_order' => 0,
        ]);

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])->assertSuccessful();

        $this->assertDatabaseHas('client_invoice_lines', ['id' => $line->id]);
        $this->assertSame(50000, (int) $adHoc->refresh()->total_amount, 'An ad-hoc invoice is left alone entirely');
    }

    /**
     * A whole-database fingerprint, so the safety assertion cannot pass by
     * checking only the rows that happened to be remembered.
     */
    /**
     * One worker on two projects, billed once per project.
     *
     * The composer words these two identically apart from the rate each quotes,
     * so nothing but the project tells them apart - which is what makes them
     * the fixture for every question about pairing concurrent charges.
     *
     * @return array{0: ClientInvoiceLine, 1: ClientInvoiceLine}
     */
    private function twoConcurrentSubcontractorCharges(): array
    {
        foreach ([['Here', 6000], ['There', 9000]] as [$name, $rate]) {
            $project = ClientProject::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'name' => $name,
            ]);

            ClientTimeEntry::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'client_project_id' => $project->id,
                'user_id' => $this->user->id,
                'worked_on' => '2024-02-14',
                'minutes' => 60,
                'description' => 'Subcontracted work',
                'is_billable' => true,
                'is_deferred' => false,
                'status' => 'approved',
                'currency' => 'USD',
                'subcontractor_cost_amount' => $rate,
                'subcontractor_cost_currency' => 'USD',
            ]);
        }

        $this->generatedHistory();

        $lines = ClientInvoiceLine::query()->where('type', 'subcontractor')->orderBy('id')->get();
        if ($lines->count() < 2) {
            $this->markTestSkipped('This fixture did not produce two concurrent subcontractor lines.');
        }

        /** @var ClientInvoiceLine $first */
        $first = $lines->firstOrFail();
        /** @var ClientInvoiceLine $last */
        $last = $lines->last();

        return [$first, $last];
    }

    /**
     * The whole comparison row the replay records for one invoice.
     *
     * @return array<string, mixed>|null
     */
    private function comparisonFor(string $invoiceNumber): ?array
    {
        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->run();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        return array_column($detail['comparisons'], null, 'invoice_number')[$invoiceNumber] ?? null;
    }

    /** The verdict the replay records for one invoice, via its report file. */
    private function verdictFor(string $invoiceNumber): ?string
    {
        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->run();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $verdicts = array_column($detail['comparisons'], 'verdict', 'invoice_number');

        return $verdicts[$invoiceNumber] ?? null;
    }

    private function fingerprint(): string
    {
        $parts = [];

        foreach (['client_invoices', 'client_invoice_lines', 'client_invoice_line_time_entries', 'client_time_entries', 'client_tasks', 'workspace_invoice_counters'] as $table) {
            // Sorted here rather than in SQL because not every one of these
            // tables has an `id`: workspace_invoice_counters is keyed on
            // workspace_id alone. `orderBy('id')` looked fine on SQLite, which
            // reinterprets an unresolvable double-quoted identifier as a string
            // literal - so it ordered by the constant 'id', silently doing
            // nothing. MySQL raises 1054 instead. Sorting the encoded rows needs
            // no key at all and is stable on both.
            $rows = DB::table($table)->get()
                ->map(static fn (object $row): string => (string) json_encode($row))
                ->sort()
                ->values()
                ->all();
            $parts[] = $table.':'.md5((string) json_encode($rows));
        }

        return implode('|', $parts);
    }

    private function generatedHistory(): ClientInvoice
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 20000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 2,
        ]);

        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => '2024-02-14',
            'minutes' => 900,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);

        // The whole history, as the original system would have produced it -
        // one hand-made invoice would leave every later cycle looking like a
        // divergence the moment the replay walked past it.
        Carbon::setTestNow(Carbon::parse('2024-06-15'));
        try {
            app(ClientInvoicingService::class)->generateAllInvoices($this->company);
        } finally {
            Carbon::setTestNow();
        }

        return ClientInvoice::query()->orderByDesc('id')->firstOrFail();
    }
}
