<?php

namespace Tests\Feature\Activity;

use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\Billing\InvoiceLifecycleService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ClientActivityRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_subjects_are_public_tenant_scoped_and_exact_retries_are_deduplicated(): void
    {
        [$manager, $workspace, $company] = $this->tenant('primary');
        $this->actingAs($manager);
        $invoice = $this->invoice($workspace, $company, 'ACT-001');
        $recorder = app(ClientActivityRecorder::class);

        $first = $recorder->record(
            $workspace,
            $company,
            'invoice.updated',
            $invoice,
            ['total_amount' => 1000, 'currency' => 'USD'],
            occurrence: 'synthetic-request-1',
        );
        $retry = $recorder->record(
            $workspace,
            $company,
            'invoice.updated',
            $invoice,
            ['total_amount' => 1000, 'currency' => 'USD'],
            occurrence: 'synthetic-request-1',
        );

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.updated')->count());
        $this->assertDatabaseHas('client_company_activity', [
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'actor_user_id' => $manager->id,
            'action' => 'invoice.updated',
            'subject_type' => 'client_invoice',
            'subject_public_id' => $invoice->public_id,
            'external_subject_id' => null,
        ]);

        try {
            $recorder->record(
                $workspace,
                $company,
                'invoice.updated',
                $invoice,
                ['total_amount' => 999, 'currency' => 'USD'],
                occurrence: 'synthetic-request-1',
            );
            $this->fail('A conflicting retry should have been rejected.');
        } catch (DomainException) {
            $this->assertSame(1000, ClientCompanyActivity::query()->where('action', 'invoice.updated')->sole()->payload['total_amount']);
        }
    }

    public function test_foreign_subjects_and_actors_are_rejected_without_leaking_activity(): void
    {
        [$manager, $workspace, $company] = $this->tenant('selected');
        [$outsider, $otherWorkspace, $otherCompany] = $this->tenant('foreign');
        $foreignInvoice = $this->invoice($otherWorkspace, $otherCompany, 'ACT-FOREIGN');
        $recorder = app(ClientActivityRecorder::class);

        try {
            $recorder->record($workspace, $company, 'invoice.updated', $foreignInvoice, actor: $manager);
            $this->fail('A foreign subject should have been rejected.');
        } catch (DomainException) {
            $this->assertDatabaseMissing('client_company_activity', [
                'workspace_id' => $workspace->id,
                'action' => 'invoice.updated',
            ]);
        }

        $localInvoice = $this->invoice($workspace, $company, 'ACT-LOCAL');
        $this->expectException(DomainException::class);
        $recorder->record($workspace, $company, 'invoice.updated', $localInvoice, actor: $outsider);
    }

    public function test_raw_or_sensitive_payload_material_is_refused(): void
    {
        [, $workspace, $company] = $this->tenant('safe-payload');
        $invoice = $this->invoice($workspace, $company, 'ACT-SAFE');

        try {
            app(ClientActivityRecorder::class)->record(
                $workspace,
                $company,
                'invoice.updated',
                $invoice,
                ['provider_payload' => ['client_secret' => 'synthetic-secret-that-must-not-persist']],
            );
            $this->fail('Sensitive payload material should have been rejected.');
        } catch (DomainException) {
            $this->assertDatabaseMissing('client_company_activity', ['action' => 'invoice.updated']);
            $this->assertStringNotContainsString(
                'synthetic-secret-that-must-not-persist',
                json_encode(ClientCompanyActivity::query()->pluck('payload')->all(), JSON_THROW_ON_ERROR),
            );
        }
    }

    public function test_business_row_and_activity_roll_back_together(): void
    {
        [, $workspace, $company] = $this->tenant('rollback');

        try {
            DB::transaction(function () use ($workspace, $company): void {
                $this->invoice($workspace, $company, 'ACT-ROLLBACK');
                throw new RuntimeException('Synthetic rollback.');
            });
            $this->fail('The synthetic transaction should have rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic rollback.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('client_invoices', ['invoice_number' => 'ACT-ROLLBACK']);
        $this->assertDatabaseCount('client_company_activity', 0);
    }

    private function invoice(Workspace $workspace, ClientCompany $company, string $number): ClientInvoice
    {
        return app(InvoiceLifecycleService::class)->createDraft($workspace, $company, [
            'invoice_number' => $number,
            'currency' => 'USD',
        ], [[
            'type' => 'service',
            'description' => 'Synthetic activity service',
            'quantity' => '1',
            'unit_amount' => 1000,
            'tax_amount' => 0,
        ]]);
    }

    /** @return array{User, Workspace, ClientCompany} */
    private function tenant(string $suffix): array
    {
        $manager = User::factory()->create(['email' => "activity-{$suffix}@synthetic.test"]);
        $workspace = Workspace::query()->create([
            'name' => "Activity {$suffix}",
            'slug' => "activity-{$suffix}",
        ]);
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => "Activity Client {$suffix}",
            'slug' => "activity-client-{$suffix}",
        ]);

        return [$manager, $workspace, $company];
    }
}
