<?php

namespace Tests\Feature\ExternalImport;

use App\Services\ExternalImport\ExternalImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * What the importer writes into an agreement, column by column.
 *
 * `ImportedColumnCoverageTest` asserts that every destination column is
 * *written*. It says nothing about what is written into it, and this mapping is
 * almost entirely defaults and coercions: a title that falls back, a status
 * derived from two dates, three currency-to-minor-unit conversions, four
 * hour-to-minute conversions, two pairs where a monthly term takes precedence
 * over a period one, and five casts. Any of them could be wrong in a way that
 * imports cleanly and bills incorrectly.
 *
 * Two rows, asserted whole rather than field by field. A populated one pins
 * every conversion and every precedence; a minimal one pins every default and
 * every null. Asserting the arrays entire is what makes the pair meaningful - a
 * per-field assertion set silently stops covering a field the day someone adds
 * one, which is the failure mode the coverage test above exists to catch and
 * this one would otherwise reproduce.
 *
 * Written for #147, which changed one value in this mapping - `starts_on` now
 * refuses rather than defaulting - and in doing so revealed that the other
 * twenty-odd had no assertion behind them at all.
 */
final class ImportedAgreementMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Everything the source can state, with the two overlapping pairs disagreeing.
     *
     * `monthly_retainer_fee` and `retainer_fee` both exist in the source, as do
     * `monthly_retainer_hours` and `retainer_hours`. The monthly one wins for
     * the agreement-level term while the period one is kept separately, so the
     * fixture gives them different values - equal ones would let a mapping that
     * read the wrong member of either pair pass.
     */
    public function test_a_fully_stated_source_row_maps_every_column(): void
    {
        $this->assertSame([
            'client_company_id' => null,
            'client_project_id' => null,
            'source_proposal_id' => null,
            'title' => 'Support Retainer',
            'status' => 'terminated',
            'starts_on' => '2024-03-01',
            'ends_on' => '2025-02-28',
            'agreement_text' => 'The terms.',
            'is_visible_to_client' => true,
            'currency' => 'EUR',
            'hourly_rate_amount' => 18750,
            'retainer_amount' => 250000,
            'retainer_minutes' => 630,
            'billing_cadence' => 'quarterly',
            'activated_at' => '2024-03-01',
            'signed_at' => '2024-02-20',
            'signed_by_user_id' => null,
            'signer_name' => 'A. Signer',
            'signer_title' => 'Director',
            'terminated_at' => '2025-02-28',
            'catch_up_threshold_minutes' => 90,
            'period_retainer_minutes' => 480,
            'period_retainer_amount' => 200000,
            'rollover_months' => 3,
            'initial_rollover_minutes' => 150,
            'bill_overage_interim' => true,
            'first_cycle_proration' => 'full_period',
            'agreement_link' => 'https://example.test/agreement',
        ], $this->mapped([
            'title' => 'Support Retainer',
            'active_date' => '2024-03-01',
            'termination_date' => '2025-02-28',
            'agreement_text' => 'The terms.',
            'is_visible_to_client' => 1,
            'currency' => 'eur',
            'hourly_rate' => '187.50',
            'monthly_retainer_fee' => '2500.00',
            'retainer_fee' => '2000.00',
            'monthly_retainer_hours' => '10.5',
            'retainer_hours' => '8',
            'billing_cadence' => 'quarterly',
            'client_company_signed_date' => '2024-02-20',
            'client_company_signed_name' => 'A. Signer',
            'client_company_signed_title' => 'Director',
            'catch_up_threshold_hours' => '1.5',
            'rollover_months' => '3',
            'initial_rollover_hours' => '2.5',
            'bill_overage_interim' => '1',
            'first_cycle_proration' => 'full_period',
            'agreement_link' => 'https://example.test/agreement',
        ]));
    }

    /**
     * The least a source row can state, and every default it lands on.
     *
     * `active_date` is the one field with no default - the importer refuses an
     * agreement that states no start date rather than substituting one (#147) -
     * so it is present here and nothing else is. Note that its presence is also
     * what makes `status` read `active`: an agreement with a start date and no
     * termination date is in force.
     */
    public function test_a_bare_source_row_lands_on_its_defaults(): void
    {
        $this->assertSame([
            'client_company_id' => null,
            'client_project_id' => null,
            'source_proposal_id' => null,
            'title' => 'External agreement',
            'status' => 'active',
            'starts_on' => '2024-03-01',
            'ends_on' => null,
            'agreement_text' => null,
            'is_visible_to_client' => false,
            'currency' => 'USD',
            'hourly_rate_amount' => null,
            'retainer_amount' => null,
            'retainer_minutes' => null,
            'billing_cadence' => 'monthly',
            'activated_at' => '2024-03-01',
            'signed_at' => null,
            'signed_by_user_id' => null,
            'signer_name' => null,
            'signer_title' => null,
            'terminated_at' => null,
            'catch_up_threshold_minutes' => null,
            'period_retainer_minutes' => null,
            'period_retainer_amount' => null,
            'rollover_months' => null,
            'initial_rollover_minutes' => null,
            'bill_overage_interim' => null,
            'first_cycle_proration' => null,
            'agreement_link' => null,
        ], $this->mapped(['active_date' => '2024-03-01']));
    }

    /**
     * The period pair is read when the monthly one is absent.
     *
     * `retainer_amount` and `retainer_minutes` each coalesce through two source
     * columns. The populated row above proves the monthly one wins; this proves
     * the fallback is reached rather than being dead.
     */
    public function test_the_period_terms_are_the_fallback_for_the_monthly_ones(): void
    {
        $mapped = $this->mapped([
            'active_date' => '2024-03-01',
            'retainer_fee' => '2000.00',
            'retainer_hours' => '8',
        ]);

        $this->assertSame(200000, $mapped['retainer_amount']);
        $this->assertSame(480, $mapped['retainer_minutes']);
    }

    /**
     * A row that states a termination date is terminated, dated or not.
     *
     * The status is derived from two dates and the termination one is checked
     * first, so an agreement that ended is never reported as active.
     */
    public function test_a_terminated_source_row_is_never_active(): void
    {
        $mapped = $this->mapped(['active_date' => '2024-03-01', 'termination_date' => '2024-09-30']);

        $this->assertSame('terminated', $mapped['status']);
        $this->assertSame('2024-09-30', $mapped['ends_on']);
        $this->assertSame('2024-09-30', $mapped['terminated_at']);
    }

    /**
     * The importer's mapping for one source agreement row.
     *
     * Reached by reflection, the same way `ImportedColumnCoverageTest` reaches
     * it: `attributes()` is the mapping, and running a whole import to read it
     * back off the destination would assert the round trip rather than the map.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapped(array $row): array
    {
        $service = app(ExternalImportService::class);
        $queryCache = (new ReflectionMethod($service, 'newQueryCache'))->invoke($service);

        /** @var array<string, mixed> $attributes */
        $attributes = (new ReflectionMethod($service, 'attributes'))->invokeArgs($service, [
            $row, 'agreement', 1, [], '00000000-0000-4000-8000-000000000000',
            DB::getDefaultConnection(), 'test-identity-hash', &$queryCache, [],
        ]);

        // The envelope every target type carries - workspace, public id, source
        // bookkeeping - is not this mapping's subject and differs per run.
        return array_diff_key($attributes, array_flip([
            'workspace_id', 'public_id', 'created_at', 'updated_at',
        ]));
    }
}
