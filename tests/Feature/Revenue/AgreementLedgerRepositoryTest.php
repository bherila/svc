<?php

namespace Tests\Feature\Revenue;

use App\Services\Revenue\AgreementLedgerRepository;
use App\Services\Revenue\ProjectionInputsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRevenueFixtures;
use Tests\TestCase;

/**
 * The capacity ledgers the roster's agreement health reads.
 */
final class AgreementLedgerRepositoryTest extends TestCase
{
    use BuildsRevenueFixtures;
    use RefreshDatabase;

    private const string AS_OF = '2026-11-06';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-06 12:00:00');
    }

    public function test_the_agreement_ledgers_are_read_for_agreements_in_force_only(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $inputs = app(ProjectionInputsRepository::class)->load($mine['workspace'], CarbonImmutable::parse(self::AS_OF));

        $ledgers = app(AgreementLedgerRepository::class)->inForce($mine['workspace'], $inputs);

        // Alpha and Gamma are in force; Beta has not started.
        $this->assertSame([$inputs->agreements[0]->id, $inputs->agreements[1]->id], array_keys($ledgers));
        $this->assertNotNull($ledgers[$inputs->agreements[0]->id]);
    }
}
