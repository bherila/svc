<?php

namespace Tests\Feature\Engagement;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Correcting an agreement that was recorded wrong.
 *
 * The imported agreements arrived titled "Legacy Agreement", which is what this
 * endpoint exists for; the rest of the terms are here because an operator who
 * can fix a name and nothing else will fix the name and leave the wrong rate.
 *
 * What these assert, beyond the write landing: that a partial request leaves
 * the terms it did not mention alone, that a null clears a term rather than
 * writing a zero, and that neither a scoped member nor another tenant can reach
 * the row at all.
 */
class AgreementCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Route::has('svc.engagement.agreements.update')) {
            require base_path('routes/engagement.php');
        }
    }

    public function test_an_operator_renames_a_legacy_agreement_without_touching_its_terms(): void
    {
        [$owner, $workspace, $agreement] = $this->agreement([
            'title' => 'Legacy Agreement',
            'hourly_rate_amount' => 22500,
            'retainer_minutes' => 1200,
            'retainer_amount' => 450000,
            'rollover_months' => 3,
        ]);

        $this->actingAs($owner)
            ->patchJson($this->url($workspace, $agreement), ['title' => 'Support retainer, 2026'])
            ->assertOk();

        $agreement->refresh();

        $this->assertSame('Support retainer, 2026', $agreement->title);
        // The whole point of `sometimes`: a two-field form must not blank the
        // fifteen terms it never showed.
        $this->assertSame(22500, (int) $agreement->hourly_rate_amount);
        $this->assertSame(1200, (int) $agreement->retainer_minutes);
        $this->assertSame(450000, (int) $agreement->retainer_amount);
        $this->assertSame(3, (int) $agreement->rollover_months);
    }

    public function test_an_explicit_null_unsets_a_term_rather_than_zeroing_it(): void
    {
        [$owner, $workspace, $agreement] = $this->agreement(['hourly_rate_amount' => 22500]);

        $this->actingAs($owner)
            ->patchJson($this->url($workspace, $agreement), ['hourly_rate_amount' => null])
            ->assertOk();

        // Null is "this agreement states no rate", and the rate lookup refuses
        // rather than pricing the work at nothing. A zero would be a price.
        $this->assertNull($agreement->fresh()->hourly_rate_amount);
    }

    public function test_the_correction_is_recorded_with_what_actually_changed(): void
    {
        [$owner, $workspace, $agreement] = $this->agreement(['title' => 'Legacy Agreement']);

        $this->actingAs($owner)
            ->patchJson($this->url($workspace, $agreement), ['title' => 'Named at last'])
            ->assertOk();

        $activity = ClientCompanyActivity::query()
            ->where('action', 'agreement.updated')
            ->sole();

        $changes = $activity->payload['changes'] ?? [];

        $this->assertSame('Legacy Agreement', $changes['title']['old'] ?? null);
        $this->assertSame('Named at last', $changes['title']['new'] ?? null);
    }

    public function test_a_request_that_changes_nothing_records_nothing(): void
    {
        [$owner, $workspace, $agreement] = $this->agreement(['title' => 'Already right']);

        $this->actingAs($owner)
            ->patchJson($this->url($workspace, $agreement), ['title' => 'Already right'])
            ->assertOk();

        $this->assertSame(
            0,
            ClientCompanyActivity::query()->where('action', 'agreement.updated')->count(),
        );
    }

    public function test_an_end_date_before_the_start_is_refused_even_when_the_start_is_not_sent(): void
    {
        [$owner, $workspace, $agreement] = $this->agreement(['starts_on' => '2026-03-01']);

        // The rule this replaces was `after_or_equal:starts_on`, which passes
        // anything when the request carries no start to compare against.
        $this->actingAs($owner)
            ->patchJson($this->url($workspace, $agreement), ['ends_on' => '2026-02-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_on');

        $this->assertNull($agreement->fresh()->ends_on);
    }

    public function test_a_catch_up_threshold_larger_than_the_retainer_is_refused(): void
    {
        [$owner, $workspace, $agreement] = $this->agreement([
            'billing_cadence' => 'monthly',
            'retainer_minutes' => 600,
        ]);

        // The model's own guard: a threshold the retainer cannot hold means
        // every invoice bills catch-up hours forever to restore a buffer that
        // does not fit, and that reads as a pricing decision nobody made.
        $this->actingAs($owner)
            ->patchJson($this->url($workspace, $agreement), ['catch_up_threshold_minutes' => 900])
            ->assertStatus(422);

        $this->assertNull($agreement->fresh()->catch_up_threshold_minutes);
    }

    public function test_a_member_who_cannot_manage_the_workspace_cannot_correct_its_agreements(): void
    {
        [, $workspace, $agreement] = $this->agreement(['title' => 'Legacy Agreement']);
        $viewer = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $viewer->id, 'role' => 'viewer']);

        $this->actingAs($viewer)
            ->patchJson($this->url($workspace, $agreement), ['title' => 'Renamed by a viewer'])
            ->assertForbidden();

        $this->assertSame('Legacy Agreement', $agreement->fresh()->title);
    }

    public function test_another_tenants_owner_cannot_correct_this_agreement(): void
    {
        [, , $agreement] = $this->agreement(['title' => 'Legacy Agreement']);
        [$intruder, $theirWorkspace] = $this->agreement();

        // Their own workspace in the URL, this workspace's agreement id after
        // it. Agreement ids are unique across every tenant, so a pasted one
        // arrives bound and plausible - and the route's workspace gate passes,
        // because it is their workspace.
        $this->actingAs($intruder)
            ->patchJson($this->url($theirWorkspace, $agreement), ['title' => 'Renamed by a stranger'])
            ->assertNotFound();

        $this->assertSame('Legacy Agreement', $agreement->fresh()->title);
    }

    private function url(Workspace $workspace, ClientAgreement $agreement): string
    {
        return "/workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}";
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: User, 1: Workspace, 2: ClientAgreement}
     */
    private function agreement(array $attributes = []): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Synthetic Correction Workspace',
            'slug' => 'synthetic-correction-workspace-'.uniqid(),
        ]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Correction Client',
            'slug' => 'synthetic-correction-client-'.uniqid(),
        ]);

        // The overrides come first: `+` keeps the left operand's keys, so
        // defaults on the left would silently win and every test here would
        // exercise the same agreement.
        $agreement = ClientAgreement::query()->create($attributes + [
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Legacy Agreement',
            'status' => 'draft',
            'starts_on' => '2026-01-01',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
        ]);

        return [$owner, $workspace, $agreement];
    }
}
