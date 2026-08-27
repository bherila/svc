<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Generated values must fit the columns they are written to.
 *
 * The suite runs on SQLite, which is dynamically typed and will happily store
 * the string `1:30` in a decimal column. MySQL in strict mode - which is what
 * production runs - rejects it outright. A completely green test suite
 * therefore proved nothing about whether the generator could write a single
 * invoice line in production, and it could not: the predecessor had migrated
 * `quantity` to varchar to hold `h:mm`, and this schema kept it decimal.
 *
 * These assertions check the values rather than trusting the driver.
 */
final class GeneratedLineColumnTypesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_generated_line_writes_a_numeric_quantity(): void
    {
        // Drivers name it differently - MySQL says decimal, SQLite says numeric.
        // What matters is that it is not a string column.
        $this->assertContains(
            Schema::getColumnType('client_invoice_lines', 'quantity'),
            ['decimal', 'numeric'],
            'If this column becomes a string, the h:mm form is viable again and this test should be revisited.',
        );

        $invoice = $this->generate();

        $quantities = DB::table('client_invoice_lines')
            ->where('client_invoice_id', $invoice->id)
            ->pluck('quantity');

        $this->assertNotEmpty($quantities, 'The fixture must produce lines or this proves nothing');

        foreach ($quantities as $quantity) {
            $this->assertTrue(
                is_numeric($quantity),
                sprintf('quantity %s is not numeric and MySQL would reject it', var_export($quantity, true)),
            );
        }
    }

    /**
     * The readable h:mm form has not been lost - it moved to where a person
     * actually reads it.
     */
    public function test_the_readable_duration_still_appears_in_the_description(): void
    {
        $invoice = $this->generate();

        $descriptions = implode(' | ', $invoice->lines()->pluck('description')->all());

        $this->assertMatchesRegularExpression('/\d+:\d{2}/', $descriptions);
    }

    private function generate(): ClientInvoice
    {
        $workspace = Workspace::query()->create(['name' => 'Types', 'slug' => 'types']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => 'Types Client', 'slug' => 'types-client',
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Types Project',
        ]);
        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Retainer',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2024-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 150000, 'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 20000, 'rollover_months' => 2,
        ]);
        ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'client_project_id' => $project->id, 'user_id' => User::factory()->create()->id,
            'worked_on' => '2024-02-14', 'minutes' => 930, 'description' => 'Work',
            'is_billable' => true, 'is_deferred' => false, 'status' => 'approved', 'currency' => 'USD',
        ]);

        return app(ClientInvoicingService::class)->generateInvoice(
            $company,
            Carbon::parse('2024-02-01'),
            Carbon::parse('2024-02-29'),
        )->refresh();
    }
}
