<?php

namespace Tests\Unit\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\ExternalImportItem;
use App\Models\ExternalImportRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\LateWork;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(LateWork::class)]
final class LateWorkProvenanceTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{bool, string, bool, bool}> */
    public static function provenance(): iterable
    {
        yield 'no import record, no time links' => [false, 'invoice', true, true];
        yield 'matching imported invoice' => [true, 'invoice', true, true];
        yield 'same target in another workspace' => [true, 'invoice', false, true];
        yield 'same public id on another resource type' => [true, 'time_entry', true, true];
        yield 'another imported invoice in this workspace' => [true, 'invoice', true, false];
    }

    #[DataProvider('provenance')]
    public function test_only_this_invoices_recorded_import_provenance_excludes_its_period(bool $recorded, string $targetType, bool $sameWorkspace, bool $sameInvoice): void
    {
        $this->travelTo(Carbon::parse('2026-02-02 12:00:00'));
        $workspace = Workspace::query()->create(['name' => 'Synthetic provenance', 'slug' => 'synthetic-provenance']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client',
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project',
        ]);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'title' => 'Synthetic agreement', 'status' => 'active', 'starts_on' => '2026-01-01', 'currency' => 'USD',
        ]);
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'invoice_number' => 'SYNTHETIC-JAN', 'status' => 'paid', 'invoice_kind' => 'cadence_period', 'currency' => 'USD',
            'service_period_start' => '2026-01-01', 'service_period_end' => '2026-01-31', 'issued_at' => '2026-02-01 09:00:00',
        ]);
        $late = ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-01-06', 'minutes' => 1110, 'description' => 'Synthetic late work',
            'status' => 'approved', 'is_billable' => true, 'currency' => 'USD',
        ]);

        if ($recorded) {
            $elsewhere = $sameWorkspace ? $workspace : Workspace::query()->create(['name' => 'Synthetic elsewhere', 'slug' => 'synthetic-elsewhere']);
            $run = ExternalImportRun::query()->create([
                'workspace_id' => $elsewhere->id, 'source_connection' => 'synthetic-retired',
                'source_identity_hash' => hash('sha256', 'synthetic-retired'), 'mode' => 'apply', 'status' => 'completed',
            ]);
            ExternalImportItem::query()->create([
                'external_import_run_id' => $run->id, 'source_connection' => $run->source_connection,
                'source_identity_hash' => $run->source_identity_hash, 'source_table' => 'client_invoices', 'source_key' => 'synthetic-invoice',
                'target_type' => $targetType, 'target_public_id' => $sameInvoice ? $invoice->public_id : (string) Str::uuid(),
                'source_fingerprint' => hash('sha256', 'synthetic-invoice'), 'status' => 'imported',
            ]);
        }

        $excluded = $recorded && $targetType === 'invoice' && $sameWorkspace && $sameInvoice;
        $this->assertSame($excluded ? [] : [$late->id], (new LateWork)->ids(
            ClientTimeEntry::query()->where('workspace_id', $workspace->id)->unbilled(), $agreement, Carbon::parse('2026-03-01'),
        ));
    }
}
