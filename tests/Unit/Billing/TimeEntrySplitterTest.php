<?php

namespace Tests\Unit\Billing;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\TimeEntrySplitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class TimeEntrySplitterTest extends TestCase
{
    use RefreshDatabase;

    protected TimeEntrySplitter $splitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->splitter = new TimeEntrySplitter;
    }

    public function test_allocate_time_entries_with_sufficient_prior_month_capacity(): void
    {
        $user = $this->createUser();
        $entries = new Collection([
            $this->createTimeEntry($user->id, 120, '2024-01-15'), // 2 hours
            $this->createTimeEntry($user->id, 60, '2024-01-16'),  // 1 hour
        ]);

        $plan = $this->splitter->allocateTimeEntries(
            $entries,
            priorMonthRetainerCapacity: 5.0,
            currentMonthRetainerCapacity: 0.0,
            catchUpThresholdHours: 1.0
        );

        $this->assertCount(2, $plan->priorMonthRetainerFragments);
        $this->assertCount(0, $plan->currentMonthRetainerFragments);
        $this->assertCount(0, $plan->catchUpFragments);
        $this->assertCount(0, $plan->billableCatchupFragments);
        $this->assertEquals(3.0, $plan->totalPriorMonthRetainerHours);
    }

    public function test_allocate_time_entries_spills_to_current_month(): void
    {
        $user = $this->createUser();
        $entries = new Collection([
            $this->createTimeEntry($user->id, 120, '2024-01-15'), // 2 hours
            $this->createTimeEntry($user->id, 120, '2024-01-16'), // 2 hours
        ]);

        $plan = $this->splitter->allocateTimeEntries(
            $entries,
            priorMonthRetainerCapacity: 3.0,
            currentMonthRetainerCapacity: 2.0,
            catchUpThresholdHours: 1.0
        );

        $this->assertEquals(3.0, $plan->totalPriorMonthRetainerHours);
        $this->assertEquals(1.0, $plan->totalCurrentMonthRetainerHours);
        $this->assertCount(2, $plan->priorMonthRetainerFragments);
        $this->assertCount(1, $plan->currentMonthRetainerFragments);
    }

    public function test_allocate_time_entries_with_catch_up_needed(): void
    {
        $user = $this->createUser();
        $entries = new Collection([
            $this->createTimeEntry($user->id, 300, '2024-01-15'), // 5 hours
        ]);

        $plan = $this->splitter->allocateTimeEntries(
            $entries,
            priorMonthRetainerCapacity: 0.0,
            currentMonthRetainerCapacity: 2.0,
            catchUpThresholdHours: 1.0
        );

        // With 2h current retainer and 1h threshold:
        // After using 2h retainer, availability = 0 which is < 1h threshold
        // So we need catch-up to pay down debt = 0
        // Since total capacity (2h) < threshold (1h), no catch-up billing
        // Remaining 3h goes to billable catch-up
        $this->assertEquals(0.0, $plan->totalPriorMonthRetainerHours);
        $this->assertEquals(2.0, $plan->totalCurrentMonthRetainerHours);
        $this->assertEquals(0.0, $plan->totalCatchUpHours); // No catch-up needed when starting with positive availability
        $this->assertEquals(3.0, $plan->totalBillableCatchupHours);
    }

    public function test_allocate_time_entries_splits_single_entry(): void
    {
        $user = $this->createUser();
        $entries = new Collection([
            $this->createTimeEntry($user->id, 180, '2024-01-15'), // 3 hours
        ]);

        $plan = $this->splitter->allocateTimeEntries(
            $entries,
            priorMonthRetainerCapacity: 1.0,
            currentMonthRetainerCapacity: 1.0,
            catchUpThresholdHours: 1.0
        );

        // 1h prior, 1h current, 1h billable catch-up
        $this->assertEquals(1.0, $plan->totalPriorMonthRetainerHours);
        $this->assertEquals(1.0, $plan->totalCurrentMonthRetainerHours);
        $this->assertEquals(0.0, $plan->totalCatchUpHours);
        $this->assertEquals(1.0, $plan->totalBillableCatchupHours);
        $this->assertCount(3, $plan->getAllFragments());
    }

    public function test_allocate_time_entries_zero_capacity(): void
    {
        $user = $this->createUser();
        $entries = new Collection([
            $this->createTimeEntry($user->id, 120, '2024-01-15'), // 2 hours
        ]);

        $plan = $this->splitter->allocateTimeEntries(
            $entries,
            priorMonthRetainerCapacity: 0.0,
            currentMonthRetainerCapacity: 0.0,
            catchUpThresholdHours: 1.0
        );

        // All goes to catch-up (1h) and billable (1h)
        $this->assertEquals(0.0, $plan->totalPriorMonthRetainerHours);
        $this->assertEquals(0.0, $plan->totalCurrentMonthRetainerHours);
        $this->assertEquals(1.0, $plan->totalCatchUpHours);
        $this->assertEquals(1.0, $plan->totalBillableCatchupHours);
    }

    public function test_allocate_time_entries_chronological_order(): void
    {
        $user = $this->createUser();
        $entries = new Collection([
            $this->createTimeEntry($user->id, 60, '2024-01-20'),
            $this->createTimeEntry($user->id, 60, '2024-01-10'),
            $this->createTimeEntry($user->id, 60, '2024-01-15'),
        ]);

        $plan = $this->splitter->allocateTimeEntries(
            $entries,
            priorMonthRetainerCapacity: 2.5, // 2.5 hours = 150 minutes
            currentMonthRetainerCapacity: 0.0,
            catchUpThresholdHours: 0.0
        );

        // Should be allocated in date order: 01-10 (60min), 01-15 (60min), 01-20 (30min of 60min)
        // The third entry (01-20) should be split: 30min to retainer, 30min to billable
        $this->assertEquals(2.5, $plan->totalPriorMonthRetainerHours); // 150 minutes
        $this->assertEquals(0.5, $plan->totalBillableCatchupHours); // 30 minutes

        $fragments = $plan->priorMonthRetainerFragments;
        $this->assertCount(3, $fragments);
        $this->assertEquals('2024-01-10', $fragments[0]->dateWorked);
        $this->assertEquals(60, $fragments[0]->minutes);
        $this->assertEquals('2024-01-15', $fragments[1]->dateWorked);
        $this->assertEquals(60, $fragments[1]->minutes);
        $this->assertEquals('2024-01-20', $fragments[2]->dateWorked);
        $this->assertEquals(30, $fragments[2]->minutes);
    }

    public function test_split_entry_creates_two_entries(): void
    {
        $user = $this->createUser();
        $company = $this->createClientCompany();
        $project = ClientProject::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'name' => 'Splitter project',
        ]);

        $entry = $this->entryOn($project, $user, 180, '2024-01-15');

        $result = $this->splitter->splitEntry($entry, 120);

        $this->assertEquals(120, $result['primary']->minutes_worked);
        $this->assertEquals(60, $result['overflow']->minutes_worked);
        $this->assertEquals($entry->id, $result['primary']->id);
        $this->assertNotEquals($entry->id, $result['overflow']->id);
        $this->assertEquals('Test task', $result['overflow']->name);
        $this->assertEquals('2024-01-15', $result['overflow']->date_worked->format('Y-m-d'));
        $this->assertEquals('Software Development', $result['overflow']->job_type);
        $this->assertNull($result['overflow']->client_invoice_line_id);
    }

    public function test_split_entry_throws_on_invalid_split_point(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = $this->createUser();
        $company = $this->createClientCompany();
        $project = ClientProject::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'name' => 'Splitter project',
        ]);

        $entry = $this->entryOn($project, $user, 180, '2024-01-15');

        $this->splitter->splitEntry($entry, 0);
    }

    public function test_split_entry_throws_on_split_beyond_total(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = $this->createUser();
        $company = $this->createClientCompany();
        $project = ClientProject::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'name' => 'Splitter project',
        ]);

        $entry = $this->entryOn($project, $user, 180, '2024-01-15');

        $this->splitter->splitEntry($entry, 180);
    }

    public function test_allocation_plan_helper_methods(): void
    {
        $user = $this->createUser();
        $entries = new Collection([
            $this->createTimeEntry($user->id, 120, '2024-01-15'),
        ]);

        $plan = $this->splitter->allocateTimeEntries(
            $entries,
            priorMonthRetainerCapacity: 1.0,
            currentMonthRetainerCapacity: 1.0,
            catchUpThresholdHours: 0.0
        );

        $this->assertEquals(2.0, $plan->getTotalHours());
        $this->assertCount(2, $plan->getAllFragments());
    }

    /**
     * Only construction is translated to this schema; every assertion in this
     * file is the one the predecessor implementation shipped.
     */
    protected function createTimeEntry(int $userId, int $minutes, string $date, ?int $id = null): ClientTimeEntry
    {
        if (! isset($this->testCompany)) {
            $this->testCompany = $this->createClientCompany();
        }

        if (! isset($this->testProject)) {
            $this->testProject = ClientProject::query()->create([
                'workspace_id' => $this->testCompany->workspace_id,
                'client_company_id' => $this->testCompany->id,
                'name' => 'Splitter project',
            ]);
        }

        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->testCompany->workspace_id,
            'client_company_id' => $this->testCompany->id,
            'client_project_id' => $this->testProject->id,
            'user_id' => $userId,
            'description' => 'Test task',
            'minutes' => $minutes,
            'worked_on' => $date,
            'is_billable' => true,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }

    private function entryOn(ClientProject $project, User $user, int $minutes, string $date): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $project->workspace_id,
            'client_company_id' => $project->client_company_id,
            'client_project_id' => $project->id,
            'user_id' => $user->id,
            'description' => 'Test task',
            'job_type' => 'Software Development',
            'minutes' => $minutes,
            'worked_on' => $date,
            'is_billable' => true,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }

    private function createUser(): User
    {
        return User::factory()->create();
    }

    protected function createClientCompany(): ClientCompany
    {
        $workspace = Workspace::query()->create(['name' => 'Splitter', 'slug' => 'splitter-'.uniqid()]);

        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Splitter Client',
            'slug' => 'splitter-client-'.uniqid(),
        ]);
    }

    private ?ClientCompany $testCompany = null;

    private ?ClientProject $testProject = null;
}
