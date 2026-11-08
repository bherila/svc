<?php

namespace Database\Seeders;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Synthetic data for the isolated staging deployment, and for nothing else.
 *
 * One workspace, one client company, a portal contact and four issued invoices small
 * enough to pay online. Every name is invented and every address uses a reserved
 * domain unless the operator names a test identity in `svc.staging_seed`. Safe to
 * run twice: rows are found by their obvious synthetic identifiers.
 *
 * It refuses to run in production, and it never reads or copies another database.
 */
class StagingSeeder extends Seeder
{
    public const WORKSPACE_SLUG = 'staging-synthetic';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('StagingSeeder writes synthetic data and refuses to run in production.');
        }

        $owner = $this->user((string) config('svc.staging_seed.owner_email'), 'Staging Owner');
        $portal = $this->user((string) config('svc.staging_seed.portal_email'), 'Staging Portal Contact');

        $workspace = Workspace::query()->firstOrCreate(['slug' => self::WORKSPACE_SLUG], ['name' => 'Staging Synthetic Workspace']);
        $workspace->memberships()->firstOrCreate(['user_id' => $owner->id], ['role' => 'owner']);

        $company = ClientCompany::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'slug' => 'staging-synthetic-client'],
            ['name' => 'Staging Synthetic Client'],
        );
        ClientCompanyMembership::query()->firstOrCreate(
            ['client_company_id' => $company->id, 'user_id' => $portal->id],
            ['role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY],
        );
        ClientProject::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Staging Synthetic Project'],
            ['is_visible_to_client' => true],
        );

        // Several invoices so the acceptance run can pay, decline, refund and dispute without reusing one.
        foreach ([1 => 25000, 2 => 25000, 3 => 12500, 4 => 12500] as $n => $cents) {
            $this->invoice($workspace, $company, "INV-STAGING-{$n}", $cents);
        }
    }

    private function user(string $email, string $name): User
    {
        return User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => Str::random(48)]);
    }

    private function invoice(Workspace $workspace, ClientCompany $company, string $number, int $cents): void
    {
        if (ClientInvoice::query()->where('workspace_id', $workspace->id)->where('invoice_number', $number)->exists()) {
            return;
        }

        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => $number,
            'status' => 'issued',
            'currency' => 'USD',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'service_period_start' => now()->startOfMonth()->toDateString(),
            'service_period_end' => now()->endOfMonth()->toDateString(),
            'subtotal_amount' => $cents, 'tax_amount' => 0, 'total_amount' => $cents, 'paid_amount' => 0, 'balance_amount' => $cents,
            'is_visible_to_client' => true,
        ]);

        ClientInvoiceLine::query()->create([
            'workspace_id' => $workspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => 'retainer',
            'description' => 'Synthetic staging retainer',
            'quantity' => '1',
            'unit_amount' => $cents,
            'tax_amount' => 0,
            'total_amount' => $cents,
            'sort_order' => 0,
        ]);
    }
}
