<?php

namespace Tests\Feature\Revenue;

use App\Services\Revenue\ProjectionInputsRepository;
use App\Services\Revenue\RecurringRevenueProjector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRevenueFixtures;
use Tests\TestCase;

/**
 * The projector over what the repository read: a cycle sold in advance is
 * actual and is not counted again.
 */
final class ProjectionFromRepositoryTest extends TestCase
{
    use BuildsRevenueFixtures;
    use RefreshDatabase;

    private const string AS_OF = '2026-11-06';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-06 12:00:00');
    }

    public function test_the_projection_of_the_fixture_leaves_out_what_is_already_invoiced(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $this->revenueWorkspace('theirs', 7);
        $inputs = app(ProjectionInputsRepository::class)->load($mine['workspace'], CarbonImmutable::parse(self::AS_OF));
        $projection = (new RecurringRevenueProjector)->project($inputs);
        $this->assertSame(0, $projection->agreements[0]->months['2026-11']['retainer']);
        $this->assertSame(100000, $projection->agreements[0]->months['2026-12']['retainer']);
        $this->assertSame(30000, $projection->agreements[0]->months['2027-01']['recurring_items']);
        $this->assertSame(20000, $projection->agreements[0]->overage->monthlyAverage);
    }
}
