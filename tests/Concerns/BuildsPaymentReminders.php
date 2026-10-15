<?php

namespace Tests\Concerns;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\SquareInvoiceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Square\SquareLinkService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use Carbon\CarbonImmutable;

/**
 * Synthetic payment-reminder fixtures (#438): reserved domains, obvious test
 * identifiers, and a workspace on New York time so calendar days and UTC
 * instants disagree for part of every day.
 */
trait BuildsPaymentReminders
{
    use FakesSquare;

    public const string SQUARE_PAGE = 'https://squareupsandbox.com/pay-invoice/inv:0-reminder1';

    /** @return array{Workspace, User, ClientCompany, ClientInvoice} */
    private function reminderFixture(string $slug = 'synthetic-reminders', bool $cadence = false): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic Reminders '.$slug, 'slug' => $slug.'-'.uniqid(), 'timezone' => 'America/New_York']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Reminder Client',
            'slug' => 'synthetic-reminder-client',
            'billing_email' => 'billing@reminders.example.test',
        ]);
        if ($cadence) {
            $company->forceFill(['payment_reminders_enabled_at' => CarbonImmutable::parse('2030-01-01 12:00:00', 'UTC')])->save();
        }

        return [$workspace->refresh(), $owner, $company, $this->reminderInvoice($company, 'SYN-REM-1')];
    }

    private function reminderInvoice(ClientCompany $company, string $number, string $status = 'issued', ?int $agreementId = null): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'client_agreement_id' => $agreementId,
            'invoice_number' => $number,
            'invoice_kind' => 'ad_hoc',
            'status' => $status,
            'currency' => 'USD',
            'subtotal_amount' => 12_345,
            'tax_amount' => 0,
            'total_amount' => 12_345,
            'paid_amount' => 0,
            'balance_amount' => $status === 'paid' ? 0 : 12_345,
            'issue_date' => '2030-03-01',
            'due_date' => '2030-03-15',
            'issued_at' => '2030-03-01 15:00:00',
            'is_visible_to_client' => true,
        ]);
    }

    /** Link the invoice to a mirrored Square invoice with a payment page. */
    private function linkReminderInvoiceToSquare(Workspace $workspace, User $owner, ClientInvoice $invoice, array $snapshot = []): SquareInvoiceSnapshot
    {
        $this->connectSquare($workspace);
        app(SquareLinkService::class)->link($workspace, $invoice, $owner, AgentApiVersion::for($invoice->refresh()), new SquareInvoiceReference('inv:0-reminder1', '000777-R-0003', 'USD', SquareMatchMethod::Manual), false);

        return $this->mirrorReminderSnapshot($workspace, $snapshot);
    }

    /** @param  array<string, mixed>  $overrides */
    private function mirrorReminderSnapshot(Workspace $workspace, array $overrides = []): SquareInvoiceSnapshot
    {
        return SquareInvoiceSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'square_invoice_id' => 'inv:0-reminder1',
            'invoice_number' => '000777-R-0003',
            'series_key' => '000777',
            'series_occurrence' => 3,
            'status' => 'UNPAID',
            'currency' => 'USD',
            'total_amount' => 12_345,
            'paid_amount' => 0,
            'due_on' => '2030-03-15',
            'public_url' => self::SQUARE_PAGE,
            'autopay' => false,
            'payment_requests' => [['request_type' => 'BALANCE', 'due_date' => '2030-03-15', 'reminders' => []]],
            'fingerprint' => str_repeat('a', 64),
            'synced_at' => '2030-03-01 00:00:00',
            'last_seen_at' => '2030-03-01 00:00:00',
            ...$overrides,
        ]);
    }

    /** An agreement billed in Square, and an invoice generated under it that is not linked. */
    private function squareBilledInvoice(ClientCompany $company): ClientInvoice
    {
        $agreement = ClientAgreement::query()->create(['workspace_id' => $company->workspace_id, 'client_company_id' => $company->id, 'title' => 'Synthetic Square Monthly', 'status' => 'active', 'starts_on' => '2030-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000]);
        $agreement->forceFill(['square_series_key' => '000888'])->save();

        return $this->reminderInvoice($company, 'SYN-REM-SQ', agreementId: $agreement->id);
    }
}
