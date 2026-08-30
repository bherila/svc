<?php

namespace Tests\Unit\Billing;

use App\Console\Commands\Billing\ReplayInvoicesCommand;
use App\Services\Billing\ReplayContractCorrectionClassifier;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\CorrectionFacts;
use App\Support\Billing\ReplayHistoricalCycle;
use App\Support\Billing\ReplayHistorySeed;
use App\Support\Billing\ReplayInvoiceSnapshot;
use App\Support\Billing\ReplayOpeningCapacityContext;
use App\Support\Billing\ReplayOpeningCapacityProof;
use App\Support\Billing\ReplayRecurringItemIncidence;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class ReplayContractCorrectionClassifierTest extends TestCase
{
    public function test_exact_minute_rounding_requires_both_source_allocations(): void
    {
        $line = $this->line('additional_hours', 14999, 10000, '1.5', 90, 'rounding');
        $before = [
            'currency' => 'USD', 'subtotal_amount' => 14999, 'tax_amount' => 0, 'total_amount' => 14999,
            'lines' => [$line],
        ];
        $line['total_amount'] = 15000;
        $after = [
            'currency' => 'USD', 'subtotal_amount' => 15000, 'tax_amount' => 0, 'total_amount' => 15000,
            'lines' => [$line],
        ];
        $classifier = new ReplayContractCorrectionClassifier;

        $this->assertTrue($classifier->exactMinuteArithmetic($before, $after));

        $before['lines'][0]['source_minutes']--;
        $this->assertFalse($classifier->exactMinuteArithmetic($before, $after));
        $before['lines'][0]['source_minutes']++;
        $after['lines'][0]['source_minutes']--;
        $this->assertFalse($classifier->exactMinuteArithmetic($before, $after));

        $after['lines'][0]['source_minutes']++;
        $recurring = $this->line('recurring_item', 4200, 4200, '1', 0, 'recurring');
        $recurring['recurring_item_id'] = '8';
        $before['lines'][] = $recurring;
        $after['lines'][] = [...$recurring, 'source_minutes' => 15];
        $before['subtotal_amount'] += 4200;
        $before['total_amount'] += 4200;
        $after['subtotal_amount'] += 4200;
        $after['total_amount'] += 4200;
        $this->assertFalse($classifier->exactMinuteArithmetic($before, $after));
    }

    public function test_direct_correction_proofs_cannot_waive_an_unaccounted_cycle_defect(): void
    {
        $attribute = new ReflectionMethod(ReplayInvoicesCommand::class, 'attribute');
        $cases = [
            ['exact_minute_arithmetic', ['additional_hours']],
            ['opening_recurring_item_incidence', ['recurring_item']],
            ['history_omitted_opening_capacity', ['additional_hours', 'prior_month_retainer']],
        ];

        foreach ($cases as [$proof, $types]) {
            $comparison = $attribute->invoke(new ReplayInvoicesCommand, [
                'verdict' => 'money_differs',
                $proof => true,
                'attribution_changed_types' => $types,
                'changed_fields' => ['subtotal', 'cycle'],
            ]);

            $this->assertNull($comparison['explained_by'], $proof);
        }
    }

    public function test_opening_recurring_incidence_is_proved_from_an_immutable_context(): void
    {
        $before = [
            'currency' => 'USD', 'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
            'lines' => [],
        ];
        $line = [
            ...$this->line('recurring_item', 4200, 4200, '1.0000', 0, 'opening-item'),
            'hours' => null,
            'line_date' => '2026-01-10',
            'recurring_item_id' => '8',
        ];
        $after = [
            'currency' => 'USD', 'subtotal_amount' => 4200, 'tax_amount' => 0, 'total_amount' => 4200,
            'lines' => [$line],
        ];
        $context = new ReplayRecurringItemIncidence(
            companyId: 5,
            agreementId: 7,
            itemId: 8,
            currency: 'USD',
            taxable: false,
            opensItem: true,
            lineDate: '2026-01-10',
            unitAmount: 4200,
            quantity: '1',
            taxAmount: 0,
            totalAmount: 4200,
            descriptionHash: 'opening-item-description',
        );
        $classifier = new ReplayContractCorrectionClassifier;

        $this->assertTrue($classifier->openingRecurringItemIncidence(
            '5|7|cadence_period|2026-01-01..2026-01-31@2025-12-01..2025-12-31',
            $before,
            $after,
            [$context],
        ));

        $after['lines'][0]['source_minutes'] = 15;
        $this->assertFalse($classifier->openingRecurringItemIncidence(
            '5|7|cadence_period|2026-01-01..2026-01-31@2025-12-01..2025-12-31',
            $before,
            $after,
            [$context],
        ));

        $after['lines'][0]['source_minutes'] = 0;
        $after['lines'][0]['description_hash'] = 'wrong-client-facing-description';
        $this->assertFalse($classifier->openingRecurringItemIncidence(
            '5|7|cadence_period|2026-01-01..2026-01-31@2025-12-01..2025-12-31',
            $before,
            $after,
            [$context],
        ));
    }

    public function test_opening_capacity_omission_is_proved_without_a_database(): void
    {
        $context = $this->openingCapacityContext();
        [$before, $after] = $this->allocationSnapshots();

        $this->assertSame(
            ReplayInvoiceSnapshot::fromArray($before)->contractLineMultisetOfType('retainer'),
            ReplayInvoiceSnapshot::fromArray($after)->contractLineMultisetOfType('retainer'),
            'Display wording must not make an unchanged fixed retainer look contractually different.',
        );

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

    public function test_capacity_reallocation_at_the_same_rate_is_proved_without_an_opening_seed(): void
    {
        [$before, $after] = $this->allocationSnapshots();
        $classifier = new ReplayContractCorrectionClassifier;

        $this->assertTrue($classifier->capacityReallocatedAtSameRate(
            ReplayInvoiceSnapshot::fromArray($before),
            ReplayInvoiceSnapshot::fromArray($after),
        ));

        $after['lines'][1]['unit_amount']--;
        $after['lines'][1]['total_amount'] = 59997;
        $after['subtotal_amount'] = 209997;
        $after['total_amount'] = 209997;

        $this->assertFalse($classifier->capacityReallocatedAtSameRate(
            ReplayInvoiceSnapshot::fromArray($before),
            ReplayInvoiceSnapshot::fromArray($after),
        ));
    }

    public function test_capacity_reallocation_proves_moved_sources_without_claiming_a_carried_deficit(): void
    {
        [$before, $after] = $this->allocationSnapshots();

        // The priced overage contains an unchanged ledger deficit in addition
        // to time-entry-backed work. Only the 120 source-backed minutes move.
        $before['lines'][1]['source_minutes'] = 240;
        $before['lines'][1]['source_agreement_rate_minutes'] = 240;
        $after['lines'][1]['source_minutes'] = 120;
        $after['lines'][1]['source_agreement_rate_minutes'] = 120;

        $proof = (new ReplayContractCorrectionClassifier)->historyOmittedOpeningCapacity(
            $this->openingCapacityContext(),
            ReplayInvoiceSnapshot::fromArray($before),
            ReplayInvoiceSnapshot::fromArray($after),
        );

        $this->assertInstanceOf(ReplayOpeningCapacityProof::class, $proof);
        $this->assertSame(120, $proof->movedMinutes);

        $after['lines'][1]['source_minutes']--;
        $this->assertNull((new ReplayContractCorrectionClassifier)->historyOmittedOpeningCapacity(
            $this->openingCapacityContext(),
            ReplayInvoiceSnapshot::fromArray($before),
            ReplayInvoiceSnapshot::fromArray($after),
        ));
    }

    public function test_proved_same_rate_reallocation_can_reach_capacity_attribution(): void
    {
        $command = new ReplayInvoicesCommand;
        $facts = new ReflectionProperty(ReplayInvoicesCommand::class, 'factCache');
        $facts->setValue($command, ['proved-reallocation' => new CorrectionFacts(
            rolloverMonths: 1,
            fullyUsedMonthInRolloverWindow: true,
            projectScoped: false,
            otherProjectWork: false,
            deferredWork: true,
            cycleOpensMidMonth: false,
            recurringItemAnchoredBeforeCycleOpens: false,
        )]);
        $attribute = new ReflectionMethod(ReplayInvoicesCommand::class, 'attribute');
        $comparison = [
            'key' => 'proved-reallocation',
            'verdict' => 'money_differs',
            'line_repriced' => true,
            'capacity_reallocated_at_same_rate' => true,
            'attribution_changed_types' => ['additional_hours', 'prior_month_retainer'],
            'changed_fields' => ['subtotal'],
        ];

        $attributed = $attribute->invoke($command, $comparison);

        $this->assertSame(
            ['rollover_expiry_ages_by_calendar', 'deferred_work_not_drawn_early'],
            array_column($attributed['explained_by'], 'key'),
        );

        $comparison['capacity_reallocated_at_same_rate'] = false;
        $this->assertNull($attribute->invoke($command, $comparison)['explained_by']);
    }

    public function test_proved_opening_capacity_omission_has_a_named_attribution(): void
    {
        $attribute = new ReflectionMethod(ReplayInvoicesCommand::class, 'attribute');
        $comparison = $attribute->invoke(new ReplayInvoicesCommand, [
            'verdict' => 'money_differs',
            'history_omitted_opening_capacity' => true,
            'opening_capacity_also_corrects_minute_rounding' => true,
            'attribution_changed_types' => ['additional_hours', 'prior_month_retainer'],
            'changed_fields' => ['subtotal'],
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

    public function test_opening_capacity_can_remove_all_overage_or_add_the_first_balance_line(): void
    {
        [$before, $after] = $this->allocationSnapshots();
        array_splice($after['lines'], 1, 1);
        $after['lines'][1]['hours'] = 3.5;
        $after['lines'][1]['source_minutes'] = 210;
        $after['lines'][1]['source_agreement_rate_minutes'] = 210;
        $after['lines'][2]['hours'] = 3.5;
        $after['lines'][2]['source_minutes'] = 210;
        $after['lines'][2]['source_agreement_rate_minutes'] = 210;
        $after['subtotal_amount'] = 150000;
        $after['total_amount'] = 150000;

        $removedHourly = (new ReplayContractCorrectionClassifier)->historyOmittedOpeningCapacity(
            $this->openingCapacityContext(),
            ReplayInvoiceSnapshot::fromArray($before),
            ReplayInvoiceSnapshot::fromArray($after),
        );
        $this->assertInstanceOf(ReplayOpeningCapacityProof::class, $removedHourly);
        $this->assertSame(300, $removedHourly->movedMinutes);

        [$before, $after] = $this->allocationSnapshots();
        $before['lines'] = array_slice($before['lines'], 0, 2);
        $after['lines'] = [$after['lines'][0], $after['lines'][1], $after['lines'][2]];
        $after['lines'][2]['hours'] = 2.0;

        $addedBalance = (new ReplayContractCorrectionClassifier)->historyOmittedOpeningCapacity(
            $this->openingCapacityContext(),
            ReplayInvoiceSnapshot::fromArray($before),
            ReplayInvoiceSnapshot::fromArray($after),
        );
        $this->assertInstanceOf(ReplayOpeningCapacityProof::class, $addedBalance);
        $this->assertSame(120, $addedBalance->movedMinutes);
    }

    public function test_opening_capacity_chain_consumes_and_expires_the_seeded_lot(): void
    {
        [$before, $after] = $this->allocationSnapshots();
        $row = static function (array $snapshot, string $month): array {
            $start = CarbonImmutable::parse($month.'-01');
            $snapshot['service_period_start'] = $start->toDateString();
            $snapshot['service_period_end'] = $start->endOfMonth()->toDateString();
            foreach ($snapshot['lines'] as &$line) {
                if (($line['type'] ?? null) === 'prior_month_retainer') {
                    $line['line_date'] = $snapshot['service_period_end'];
                }
            }
            unset($line);

            return $snapshot;
        };
        $decemberKey = '5|7|cadence_period|2025-12-01..2025-12-31@2025-12-01..2025-12-31';
        $januaryKey = '5|7|cadence_period|2026-01-01..2026-01-31@2026-01-01..2026-01-31';
        $marchKey = '5|7|cadence_period|2026-03-01..2026-03-31@2026-03-01..2026-03-31';
        $expected = [
            // Deliberately reverse chronological: the coordinator owns order.
            $januaryKey => $row($before, '2026-01'),
            $decemberKey => $row($before, '2025-12'),
        ];
        $actual = [
            $januaryKey => $row($after, '2026-01'),
            $decemberKey => $row($after, '2025-12'),
        ];
        $command = new ReplayInvoicesCommand;
        $contexts = new ReflectionProperty(ReplayInvoicesCommand::class, 'openingCapacityContexts');
        $contexts->setValue($command, [7 => $this->openingCapacityContext()->forRemainingMinutes(400)]);
        $prove = new ReflectionMethod(ReplayInvoicesCommand::class, 'proveOpeningCapacityChain');

        $proofs = $prove->invoke($command, $expected, $actual);
        $this->assertSame([$decemberKey], array_keys($proofs));

        $contexts->setValue($command, [7 => $this->openingCapacityContext()]);
        $this->assertSame([], $prove->invoke(
            $command,
            [$marchKey => $row($before, '2026-03')],
            [$marchKey => $row($after, '2026-03')],
        ));
    }

    public function test_opening_capacity_chain_consumes_unchanged_draws_before_later_corrections(): void
    {
        [$beforeCorrection, $afterCorrection] = $this->allocationSnapshots();
        $row = static function (array $snapshot, string $month): array {
            $start = CarbonImmutable::parse($month.'-01');
            $snapshot['service_period_start'] = $start->toDateString();
            $snapshot['service_period_end'] = $start->endOfMonth()->toDateString();
            foreach ($snapshot['lines'] as &$line) {
                if (($line['type'] ?? null) === 'prior_month_retainer') {
                    $line['line_date'] = $snapshot['service_period_end'];
                }
            }
            unset($line);

            return $snapshot;
        };

        $unchanged = $beforeCorrection;
        $unchanged['lines'] = [
            $unchanged['lines'][0],
            $this->line('prior_month_retainer', 0, 0, '0.0000', 500, 'earlier-draw'),
        ];
        $unchanged['subtotal_amount'] = 150000;
        $unchanged['total_amount'] = 150000;

        $beforeCorrection['lines'][1] = $this->line(
            'additional_hours',
            200000,
            20000,
            '10.0000',
            600,
            'hourly',
        );
        $beforeCorrection['lines'] = array_slice($beforeCorrection['lines'], 0, 2);
        $beforeCorrection['subtotal_amount'] = 350000;
        $beforeCorrection['total_amount'] = 350000;
        $afterCorrection['lines'] = [
            $afterCorrection['lines'][0],
            $this->line('prior_month_retainer', 0, 0, '0.0000', 600, 'new-draw'),
        ];
        $afterCorrection['subtotal_amount'] = 150000;
        $afterCorrection['total_amount'] = 150000;

        $earlierKey = '5|7|cadence_period|2025-12-01..2025-12-31@2025-12-01..2025-12-31';
        $laterKey = '5|7|cadence_period|2026-01-01..2026-01-31@2026-01-01..2026-01-31';
        $command = new ReplayInvoicesCommand;
        (new ReflectionProperty(ReplayInvoicesCommand::class, 'openingCapacityContexts'))
            ->setValue($command, [7 => $this->openingCapacityContext()]);
        $prove = new ReflectionMethod(ReplayInvoicesCommand::class, 'proveOpeningCapacityChain');

        $this->assertSame([], $prove->invoke(
            $command,
            [
                $earlierKey => $row($unchanged, '2025-12'),
                $laterKey => $row($beforeCorrection, '2026-01'),
            ],
            [
                $earlierKey => $row($unchanged, '2025-12'),
                $laterKey => $row($afterCorrection, '2026-01'),
            ],
        ));

        $this->assertSame([$laterKey], array_keys($prove->invoke(
            $command,
            [$laterKey => $row($beforeCorrection, '2026-01')],
            [$laterKey => $row($afterCorrection, '2026-01')],
        )), 'The later 600-minute correction is valid when the seed has not already been drawn.');

        $beforeSameRow = $beforeCorrection;
        $beforeSameRow['lines'][] = $this->line('prior_month_retainer', 0, 0, '0.0000', 500, 'same-row-draw');
        $afterSameRow = $afterCorrection;
        $afterSameRow['lines'][1] = $this->line('prior_month_retainer', 0, 0, '0.0000', 1100, 'same-row-draw');
        $this->assertSame([], $prove->invoke(
            $command,
            [$laterKey => $row($beforeSameRow, '2026-01')],
            [$laterKey => $row($afterSameRow, '2026-01')],
        ), 'An unchanged same-row draw reserves capacity before the newly moved minutes are proved.');
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
            'historical overage source allocation' => static function (array $before, array $after): array {
                $before['lines'][1]['source_minutes']--;

                return [$before, $after];
            },
            'generated overage source allocation' => static function (array $before, array $after): array {
                $after['lines'][1]['source_minutes']--;

                return [$before, $after];
            },
            'historical overage agreement-rate eligibility' => static function (array $before, array $after): array {
                $before['lines'][1]['source_agreement_rate_minutes']--;

                return [$before, $after];
            },
            'generated overage agreement-rate eligibility' => static function (array $before, array $after): array {
                $after['lines'][1]['source_agreement_rate_minutes']--;

                return [$before, $after];
            },
            'historical capacity source allocation' => static function (array $before, array $after): array {
                $before['lines'][2]['source_minutes']--;

                return [$before, $after];
            },
            'generated capacity source allocation' => static function (array $before, array $after): array {
                $after['lines'][2]['source_minutes']--;

                return [$before, $after];
            },
            'capacity agreement-rate eligibility' => static function (array $before, array $after): array {
                $after['lines'][2]['source_agreement_rate_minutes']--;

                return [$before, $after];
            },
            'historical capacity agreement-rate eligibility' => static function (array $before, array $after): array {
                $before['lines'][2]['source_agreement_rate_minutes']--;

                return [$before, $after];
            },
            'capacity recurring-item attachment' => static function (array $before, array $after): array {
                $after['lines'][2]['recurring_item_id'] = '8';

                return [$before, $after];
            },
            'capacity project attachment' => static function (array $before, array $after): array {
                $after['lines'][2]['project_id'] = '9';

                return [$before, $after];
            },
            'capacity task claim' => static function (array $before, array $after): array {
                $after['lines'][2]['claimed_by'] = 'synthetic-claim';

                return [$before, $after];
            },
            'capacity quantity' => static function (array $before, array $after): array {
                $after['lines'][2]['quantity'] = '1.0000';

                return [$before, $after];
            },
            'capacity line date' => static function (array $before, array $after): array {
                $after['lines'][2]['line_date'] = '2026-02-02';

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

        $validLine = $this->line('retainer', 150000, 150000, '1.0000', 600, 'retainer-fee');
        $validLine['line_date'] = '2026-01-01';
        $invalidContracts = [
            'unit amount' => static function (array $line): array {
                $line['unit_amount']--;

                return $line;
            },
            'quantity' => static function (array $line): array {
                $line['quantity'] = '2.0000';

                return $line;
            },
            'hours' => static function (array $line): array {
                $line['hours'] = 9.0;

                return $line;
            },
            'line date' => static function (array $line): array {
                $line['line_date'] = '2026-01-02';

                return $line;
            },
            'source allocation' => static function (array $line): array {
                $line['source_minutes'] = 1;

                return $line;
            },
        ];
        foreach ($invalidContracts as $name => $mutate) {
            $line = $mutate($validLine);
            $snapshot = ReplayInvoiceSnapshot::fromArray([
                'currency' => 'USD',
                'subtotal_amount' => 150000,
                'tax_amount' => 0,
                'total_amount' => 150000,
                'lines' => [$line],
            ]);
            $this->assertNull(ReplayOpeningCapacityContext::fromOpeningInvoice($seed, $snapshot), $name);
        }

        $command = new ReplayInvoicesCommand;
        (new ReflectionProperty(ReplayInvoicesCommand::class, 'historySeeds'))->setValue($command, [7 => $seed]);
        $openingContexts = new ReflectionMethod(ReplayInvoicesCommand::class, 'openingCapacityContexts');
        $key = '5|7|cadence_period|2025-12-01..2025-12-31@2025-12-01..2025-12-31';
        $opening = [
            'status' => 'draft',
            'currency' => 'USD',
            'service_period_start' => '2025-12-01',
            'subtotal_amount' => 150000,
            'tax_amount' => 0,
            'total_amount' => 150000,
            'lines' => [$validLine],
        ];

        $this->assertSame([], $openingContexts->invoke($command, [$key => $opening]));
        $opening['status'] = 'void';
        $this->assertSame([], $openingContexts->invoke($command, [$key => $opening]));
        $opening['status'] = 'issued';
        $this->assertArrayHasKey(7, $openingContexts->invoke($command, [$key => $opening]));
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

    public function test_generated_monthly_snapshot_must_sell_the_service_period_successor(): void
    {
        $valid = ReplayInvoiceSnapshot::fromArray([
            'currency' => 'USD',
            'cycle_start' => '2026-02-01',
            'cycle_end' => '2026-02-28',
            'service_period_start' => '2026-01-01',
            'service_period_end' => '2026-01-31',
            'lines' => [],
        ]);
        $shifted = ReplayInvoiceSnapshot::fromArray([
            'currency' => 'USD',
            'cycle_start' => '2026-03-01',
            'cycle_end' => '2026-03-31',
            'service_period_start' => '2026-01-01',
            'service_period_end' => '2026-01-31',
            'lines' => [],
        ]);

        $this->assertTrue($valid->sellsMonthlySuccessorOfServicePeriod());
        $this->assertFalse($shifted->sellsMonthlySuccessorOfServicePeriod());
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
                'lines' => [array_replace(
                    $this->line('retainer', 150000, 150000, '1.0000', 600, 'retainer-fee'),
                    ['line_date' => '2026-01-01'],
                )],
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
            'service_period_start' => '2026-02-01',
            'service_period_end' => '2026-02-01',
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
            'service_period_start' => '2026-02-01',
            'service_period_end' => '2026-02-01',
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
            'source_minutes' => $type === 'retainer' ? 0 : $minutes,
            'source_agreement_rate_minutes' => $type === 'retainer' ? 0 : $minutes,
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
            rolloverMonths: 2,
            cadence: BillingCadence::Monthly,
            agreementStart: CarbonImmutable::parse('2026-01-01'),
            agreementEnd: null,
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
