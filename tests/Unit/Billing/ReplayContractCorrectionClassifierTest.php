<?php

namespace Tests\Unit\Billing;

use App\Console\Commands\Billing\ReplayInvoicesCommand;
use App\Services\Billing\ReplayContractCorrectionClassifier;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\ReplayHistoricalCycle;
use App\Support\Billing\ReplayHistorySeed;
use App\Support\Billing\ReplayInvoiceSnapshot;
use App\Support\Billing\ReplayOpeningCapacityContext;
use App\Support\Billing\ReplayOpeningCapacityProof;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ReplayContractCorrectionClassifierTest extends TestCase
{
    public function test_opening_capacity_omission_is_proved_without_a_database(): void
    {
        $context = $this->openingCapacityContext();
        [$before, $after] = $this->allocationSnapshots();

        $proof = (new ReplayContractCorrectionClassifier)->historyOmittedOpeningCapacity(
            $context,
            ReplayInvoiceSnapshot::fromArray($before),
            ReplayInvoiceSnapshot::fromArray($after),
        );

        $this->assertInstanceOf(ReplayOpeningCapacityProof::class, $proof);
        $this->assertSame(120, $proof->movedMinutes);
        $this->assertSame(-40000, $proof->moneyDelta);
        $this->assertFalse($proof->alsoCorrectsHistoricalMinuteRounding);
    }

    public function test_proved_opening_capacity_omission_has_a_named_attribution(): void
    {
        $attribute = new ReflectionMethod(ReplayInvoicesCommand::class, 'attribute');
        $comparison = $attribute->invoke(new ReplayInvoicesCommand, [
            'verdict' => 'money_differs',
            'history_omitted_opening_capacity' => true,
            'opening_capacity_also_corrects_minute_rounding' => true,
            // The exact reallocation changes quantity and price together. Its
            // complete proof must run before the generic repricing refusal.
            'line_repriced' => true,
        ]);

        $this->assertSame(
            ['historical_replay_start_omitted_capacity', 'hourly_lines_use_exact_minutes'],
            array_column($comparison['explained_by'], 'key'),
        );
    }

    public function test_opening_capacity_proof_records_one_minor_unit_of_historical_rounding(): void
    {
        [$before, $after] = $this->allocationSnapshots();
        $before['lines'][1]['total_amount']--;
        $before['subtotal_amount']--;
        $before['total_amount']--;

        $proof = (new ReplayContractCorrectionClassifier)->historyOmittedOpeningCapacity(
            $this->openingCapacityContext(),
            ReplayInvoiceSnapshot::fromArray($before),
            ReplayInvoiceSnapshot::fromArray($after),
        );

        $this->assertInstanceOf(ReplayOpeningCapacityProof::class, $proof);
        $this->assertTrue($proof->alsoCorrectsHistoricalMinuteRounding);
        $this->assertSame(-39999, $proof->moneyDelta);
    }

    public function test_opening_capacity_proof_rejects_every_unaccounted_change(): void
    {
        $context = $this->openingCapacityContext();
        [$before, $after] = $this->allocationSnapshots();

        $mutations = [
            'currency' => static function (array $before, array $after): array {
                $after['currency'] = 'EUR';

                return [$before, $after];
            },
            'tax' => static function (array $before, array $after): array {
                $after['tax_amount'] = 1;

                return [$before, $after];
            },
            'contract fee' => static function (array $before, array $after): array {
                $after['lines'][0]['total_amount']--;

                return [$before, $after];
            },
            'hourly rate' => static function (array $before, array $after): array {
                $after['lines'][1]['unit_amount']--;

                return [$before, $after];
            },
            'priced balance line' => static function (array $before, array $after): array {
                $after['lines'][2]['total_amount'] = 1;

                return [$before, $after];
            },
            'minutes do not transfer' => static function (array $before, array $after): array {
                $after['lines'][2]['quantity'] = '3.5000';
                $after['lines'][2]['hours'] = 3.5;

                return [$before, $after];
            },
            'historical hourly total' => static function (array $before, array $after): array {
                $before['lines'][1]['total_amount'] -= 2;
                $before['subtotal_amount'] -= 2;
                $before['total_amount'] -= 2;

                return [$before, $after];
            },
            'invoice total' => static function (array $before, array $after): array {
                $after['total_amount']++;

                return [$before, $after];
            },
            'foreign agreement' => static function (array $before, array $after): array {
                $after['lines'][1]['agreement_id'] = '99';

                return [$before, $after];
            },
        ];

        foreach ($mutations as $name => $mutate) {
            [$mutatedBefore, $mutatedAfter] = $mutate($before, $after);
            $this->assertNull(
                (new ReplayContractCorrectionClassifier)->historyOmittedOpeningCapacity(
                    $context,
                    ReplayInvoiceSnapshot::fromArray($mutatedBefore),
                    ReplayInvoiceSnapshot::fromArray($mutatedAfter),
                ),
                $name,
            );
        }
    }

    public function test_opening_invoice_must_have_charged_the_seeded_retainer(): void
    {
        $seed = $this->historySeed();
        $opening = ReplayInvoiceSnapshot::fromArray([
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'lines' => [],
        ]);

        $this->assertNull(ReplayOpeningCapacityContext::fromOpeningInvoice($seed, $opening));

        $wrongFee = ReplayInvoiceSnapshot::fromArray([
            'currency' => 'USD',
            'subtotal_amount' => 149999,
            'tax_amount' => 0,
            'total_amount' => 149999,
            'lines' => [$this->line('retainer', 149999, 149999, '1.0000', 60, 'wrong-fee')],
        ]);
        $this->assertNull(ReplayOpeningCapacityContext::fromOpeningInvoice($seed, $wrongFee));
    }

    public function test_history_seed_accepts_only_a_contiguous_one_way_convention_change(): void
    {
        $valid = $this->cycles();
        $this->assertInstanceOf(ReplayHistorySeed::class, $this->makeSeed($valid));

        $switchBack = [...$valid, new ReplayHistoricalCycle(
            invoiceKind: 'cadence_period',
            cycleStart: CarbonImmutable::parse('2026-02-01'),
            cycleEnd: CarbonImmutable::parse('2026-02-28'),
            servicePeriodStart: CarbonImmutable::parse('2026-02-01'),
            servicePeriodEnd: CarbonImmutable::parse('2026-02-28'),
        )];
        $this->assertNull($this->makeSeed($switchBack));

        $gap = $valid;
        $gap[1] = new ReplayHistoricalCycle(
            invoiceKind: 'cadence_period',
            cycleStart: CarbonImmutable::parse('2026-03-01'),
            cycleEnd: CarbonImmutable::parse('2026-03-31'),
            servicePeriodStart: CarbonImmutable::parse('2026-02-01'),
            servicePeriodEnd: CarbonImmutable::parse('2026-02-28'),
        );
        $this->assertNull($this->makeSeed(array_values($gap)));
    }

    private function openingCapacityContext(): ReplayOpeningCapacityContext
    {
        $context = ReplayOpeningCapacityContext::fromOpeningInvoice(
            $this->historySeed(),
            ReplayInvoiceSnapshot::fromArray([
                'currency' => 'USD',
                'subtotal_amount' => 150000,
                'tax_amount' => 0,
                'total_amount' => 150000,
                'lines' => [$this->line('retainer', 150000, 150000, '1.0000', 60, 'retainer-fee')],
            ]),
        );
        $this->assertInstanceOf(ReplayOpeningCapacityContext::class, $context);

        return $context;
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function allocationSnapshots(): array
    {
        $retainer = $this->line('retainer', 150000, 150000, '1.0000', 60, 'retainer-fee');
        $before = [
            'currency' => 'USD',
            'subtotal_amount' => 250000,
            'tax_amount' => 0,
            'total_amount' => 250000,
            'lines' => [
                $retainer,
                $this->line('additional_hours', 100000, 20000, '5.0000', 300, 'hourly'),
                $this->line('prior_month_retainer', 0, 0, '1.0000', 60, 'prior-a'),
                $this->line('prior_month_retainer', 0, 0, '1.0000', 60, 'prior-b'),
            ],
        ];
        $generatedRetainer = $retainer;
        $generatedRetainer['description_hash'] = 'allocation-description-changed';
        $generatedRetainer['identity_hash'] = 'allocation-wording-changed';
        $after = [
            'currency' => 'USD',
            'subtotal_amount' => 210000,
            'tax_amount' => 0,
            'total_amount' => 210000,
            'lines' => [
                $generatedRetainer,
                $this->line('additional_hours', 60000, 20000, '3.0000', 180, 'hourly'),
                // These zero-value ledger lines preserve allocation in `hours`;
                // their legacy quantity is not a money or capacity measure.
                $this->line('prior_month_retainer', 0, 0, '0.0000', 120, 'prior-a'),
                $this->line('prior_month_retainer', 0, 0, '0.0000', 120, 'prior-b'),
            ],
        ];

        return [$before, $after];
    }

    /** @return array<string, mixed> */
    private function line(
        string $type,
        int $total,
        int $unit,
        string $quantity,
        int $minutes,
        string $identity,
    ): array {
        return [
            'type' => $type,
            'total_amount' => $total,
            'unit_amount' => $unit,
            'tax_amount' => 0,
            'quantity' => $quantity,
            'line_date' => '2026-02-01',
            'recurring_item_id' => '',
            'project_id' => '',
            'agreement_id' => '7',
            'claimed_by' => '',
            'description_hash' => $identity.'-description',
            'identity_hash' => $identity,
            'hours' => $minutes / 60,
        ];
    }

    private function historySeed(): ReplayHistorySeed
    {
        $seed = $this->makeSeed($this->cycles());
        $this->assertInstanceOf(ReplayHistorySeed::class, $seed);

        return $seed;
    }

    /** @param list<ReplayHistoricalCycle> $cycles */
    private function makeSeed(array $cycles): ?ReplayHistorySeed
    {
        return ReplayHistorySeed::fromHistory(
            workspaceId: 3,
            companyId: 5,
            agreementId: 7,
            currency: 'USD',
            retainerMinutes: 600,
            retainerAmount: 150000,
            cadence: BillingCadence::Monthly,
            agreementStart: CarbonImmutable::parse('2026-01-01'),
            cycles: $cycles,
        );
    }

    /** @return list<ReplayHistoricalCycle> */
    private function cycles(): array
    {
        return [
            new ReplayHistoricalCycle(
                invoiceKind: 'cadence_period',
                cycleStart: CarbonImmutable::parse('2025-12-01'),
                cycleEnd: CarbonImmutable::parse('2025-12-31'),
                servicePeriodStart: CarbonImmutable::parse('2025-12-01'),
                servicePeriodEnd: CarbonImmutable::parse('2025-12-31'),
            ),
            new ReplayHistoricalCycle(
                invoiceKind: 'cadence_period',
                cycleStart: CarbonImmutable::parse('2026-02-01'),
                cycleEnd: CarbonImmutable::parse('2026-02-28'),
                servicePeriodStart: CarbonImmutable::parse('2026-01-01'),
                servicePeriodEnd: CarbonImmutable::parse('2026-01-31'),
            ),
        ];
    }
}
