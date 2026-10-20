<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\AgreementBillingRateResolver;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The agreement rate a time entry bills at, read directly from the resolver.
 *
 * These cases were pinned through `svc:billing:derive-time-rates` until that
 * one-off importer repair was retired (#460). The resolver they exercise is
 * the one approval prices time with, so they stay.
 */
final class AgreementBillingRateResolverTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic rates', 'slug' => 'synthetic-rates']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Synthetic rates client', 'slug' => 'synthetic-rates-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic rates project',
        ]);
        $this->user = User::factory()->create();
    }

    /**
     * An agreement in force but carrying no rate prices nothing.
     *
     * The resolver refuses rather than falling through to zero. Every other
     * reader of `hourly_rate_amount` used to coerce a null to `0`, so a rate
     * that reached an invoice through those paths billed the client's excess
     * hours at no charge and read as a deliberate discount. The one place that
     * can still tell "unpriced" from "free" is this lookup, and it has to say so.
     */
    public function test_an_agreement_with_no_rate_prices_nothing(): void
    {
        $this->agreement(37500, '2026-01-01', null)
            ->forceFill(['hourly_rate_amount' => null])
            ->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No active billing rate applies');

        $this->resolve($this->entry('2026-03-14'));
    }

    /**
     * A null project scope means company-wide, and reaches every project.
     *
     * Isolating for `client_agreements.client_project_id`, one of #143's named
     * readers. Only the project scope varies: both agreements are active, both
     * cover the work date, and both carry a rate. The scoped one names a
     * different project and must not win; the null one must.
     */
    public function test_an_agreement_with_no_project_covers_work_on_any_project(): void
    {
        $other = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Some other synthetic project',
        ]);
        // The other project's agreement starts *later*, so it would win the
        // ordering if the project scope were not read at all. Without that the
        // test passes against a resolver that ignores the column entirely and
        // falls through to the id tie-break.
        $elsewhere = $this->agreement(11100, '2026-02-01', null);
        $elsewhere->forceFill(['client_project_id' => $other->id])->save();
        $companyWide = $this->agreement(22200, '2026-01-01', null);
        $companyWide->forceFill(['client_project_id' => null])->save();

        $this->assertSame(['amount' => 22200, 'currency' => 'USD'], $this->resolve($this->entry('2026-02-10')));
    }

    /**
     * A null end date means the term is open, not that it has closed.
     *
     * Isolating for `client_agreements.ends_on`. Answering *false* for the null
     * - which a bare `ends_on >= workedOn` does, because SQL says false rather
     * than unknown - would silently strand every open-ended agreement, the
     * ordinary way a retainer is written.
     */
    public function test_an_agreement_with_no_end_date_is_still_in_force(): void
    {
        // The expired agreement starts later, so it would win the ordering if
        // `ends_on` were not read - the same trap as the test above.
        $this->agreement(11100, '2026-02-01', '2026-02-28');
        $openEnded = $this->agreement(22200, '2026-01-01', null);
        $openEnded->forceFill(['ends_on' => null])->save();

        $this->assertSame(['amount' => 22200, 'currency' => 'USD'], $this->resolve($this->entry('2026-06-10')));
    }

    public function test_an_agreement_that_has_since_ended_prices_the_work_done_under_it(): void
    {
        $agreement = $this->agreement(30000, '2026-01-01', '2026-03-31');
        $agreement->forceFill(['status' => 'terminated'])->save();

        $this->assertSame(['amount' => 30000, 'currency' => 'USD'], $this->resolve($this->entry('2026-02-10')));
    }

    public function test_the_currency_is_the_one_the_rate_came_from(): void
    {
        $agreement = $this->agreement(37500, '2026-01-01', null);
        $agreement->forceFill(['currency' => 'EUR'])->save();

        $this->assertSame(['amount' => 37500, 'currency' => 'EUR'], $this->resolve($this->entry('2026-03-14')));
    }

    /** @return array{amount: int, currency: string} */
    private function resolve(ClientTimeEntry $entry): array
    {
        return app(AgreementBillingRateResolver::class)->resolve($entry);
    }

    private function agreement(int $hourlyRate, string $startsOn, ?string $endsOn): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Synthetic hourly', 'status' => 'active', 'currency' => 'USD',
            'hourly_rate_amount' => $hourlyRate,
            'starts_on' => $startsOn, 'ends_on' => $endsOn,
        ]);
    }

    private function entry(string $workedOn): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => $workedOn,
            'minutes' => 90,
            'description' => 'Synthetic work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'draft',
            'currency' => 'USD',
        ]);
    }
}
