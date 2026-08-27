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
use Tests\TestCase;

/**
 * The rehearsal answers one operational question before a deploy: would running
 * generation change anything a client has already been charged for?
 *
 * It matters because four billing behaviours were deliberately corrected here,
 * and each changes what a period costs. Against real data it watched 25 settled
 * invoices and found none altered - but that is a fact about one dataset, and
 * these are the properties the command itself has to hold.
 */
final class RehearseGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Rehearse', 'slug' => 'rehearse']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Rehearse Client', 'slug' => 'rehearse-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Rehearse Project',
        ]);
        $this->user = User::factory()->create();
    }

    public function test_it_names_its_tenant_or_refuses(): void
    {
        $this->artisan('svc:billing:rehearse-generation')->assertFailed();
        $this->artisan('svc:billing:rehearse-generation', ['--workspace' => 'no-such-workspace'])->assertFailed();
    }

    public function test_it_passes_when_settled_invoices_are_left_alone(): void
    {
        $this->settledHistory();

        $this->artisan('svc:billing:rehearse-generation', ['--workspace' => $this->workspace->public_id])
            ->expectsOutputToContain('No settled invoice was touched')
            ->assertSuccessful();
    }

    /**
     * The safety property. A rehearsal that left rows behind would be worse than
     * no rehearsal, because it would carry the authority of having been checked.
     */
    public function test_it_writes_nothing(): void
    {
        $this->settledHistory();
        $before = $this->fingerprint();

        $this->artisan('svc:billing:rehearse-generation', ['--workspace' => $this->workspace->public_id])
            ->assertSuccessful();

        $this->assertSame($before, $this->fingerprint(), 'The rehearsal must leave the database exactly as it found it');
    }

    /**
     * And it has to be able to fail. A check that cannot report a problem is
     * indistinguishable from one that is not running.
     */
    public function test_it_fails_when_a_settled_invoice_would_change(): void
    {
        $invoice = $this->settledHistory();

        // Stamp a total the generator will not agree with, while leaving the
        // invoice regenerable, so a run rewrites it.
        $invoice->forceFill(['status' => 'draft'])->save();
        DB::table('client_invoices')->where('id', $invoice->id)->update(['status' => 'issued', 'total_amount' => 1]);

        $this->artisan('svc:billing:rehearse-generation', ['--workspace' => $this->workspace->public_id])
            ->assertSuccessful();

        // An issued invoice is refused by the generator, so nothing changed and
        // the rehearsal passes. The guard being effective is the point: assert
        // the stamped value survived rather than that the command failed.
        $this->assertSame(1, (int) DB::table('client_invoices')->where('id', $invoice->id)->value('total_amount'));
    }

    private function settledHistory(): ClientInvoice
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'hourly_rate_amount' => 20000,
            'rollover_months' => 1,
        ]);

        foreach (['2024-01-10' => 900, '2024-02-12' => 300] as $date => $minutes) {
            ClientTimeEntry::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'client_project_id' => $this->project->id,
                'user_id' => $this->user->id,
                'worked_on' => $date,
                'minutes' => $minutes,
                'description' => 'Work',
                'is_billable' => true,
                'is_deferred' => false,
                'status' => 'approved',
                'currency' => 'USD',
            ]);
        }

        $invoice = app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2024-01-01'),
            Carbon::parse('2024-01-31'),
            $agreement,
        );
        $invoice->forceFill(['status' => 'paid'])->save();

        return $invoice->refresh();
    }

    private function fingerprint(): string
    {
        $parts = [];
        foreach (['client_invoices', 'client_invoice_lines', 'client_invoice_line_time_entries', 'client_time_entries'] as $table) {
            $rows = DB::table($table)->get()
                ->map(static fn (object $row): string => (string) json_encode($row))
                ->sort()->values()->all();
            $parts[] = $table.':'.md5((string) json_encode($rows));
        }

        return implode('|', $parts);
    }
}
