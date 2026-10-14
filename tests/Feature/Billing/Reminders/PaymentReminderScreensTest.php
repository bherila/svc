<?php

namespace Tests\Feature\Billing\Reminders;

use App\Models\ClientInvoiceAdministratorNotification;
use App\Models\InvoiceReminderLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsPaymentReminders;
use Tests\TestCase;

/**
 * The operator's reminder screens (#438): finished URLs from the server, and
 * nothing offered that the API would refuse.
 */
final class PaymentReminderScreensTest extends TestCase
{
    use BuildsPaymentReminders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2030-03-15 14:00:00');
        config(['inertia.ssr.enabled' => false]);
    }

    public function test_the_invoice_page_offers_a_reminder_only_when_one_may_be_sent(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->reminderFixture();

        $this->actingAs($owner)->get(route('clients.invoice', [$workspace, $company, $invoice]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment_reminders.send_href', route('agent-api.v1.invoices.reminders.store', [$workspace, $invoice->public_id], absolute: false))
                ->where('payment_reminders.manual.pay_link', 'none')
                ->where('payment_reminders.cadence.enabled', false)
                ->where('payment_reminders.reminders', []));

        $billed = $this->squareBilledInvoice($company);
        $this->actingAs($owner)->get(route('clients.invoice', [$workspace, $company, $billed]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment_reminders.send_href', null)
                ->where('payment_reminders.manual.refusal.code', 'square_not_linked'));
        $this->assertSame(0, InvoiceReminderLog::query()->count());
    }

    public function test_the_invoice_page_offers_no_in_place_correction_once_a_reminder_was_sent(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->reminderFixture();
        // Original evidence present, so only the reminder stands in the way.
        ClientInvoiceAdministratorNotification::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'invoice_revision' => 1,
            'status' => 'sent', 'pdf_content_base64' => base64_encode('synthetic'), 'pdf_filename' => 'synthetic.pdf', 'attempt_count' => 1,
            'subject' => 'Synthetic', 'open_url' => 'https://svc.example.test/synthetic', 'client_name' => 'Synthetic', 'invoice_number' => 'SYN-REM-1',
            'currency' => 'USD', 'total_amount' => 12_345,
        ]);
        $page = fn () => $this->actingAs($owner)->get(route('clients.invoice', [$workspace, $company, $invoice]))->assertOk();
        $page()->assertInertia(fn (Assert $inertia) => $inertia->where('actions.correct', "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/correct"));

        InvoiceReminderLog::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'origin' => 'manual', 'channel' => 'email', 'pay_link' => 'none',
            'recipients' => ['billing@reminders.example.test'], 'subject' => 'Synthetic', 'currency' => 'USD', 'amount_due' => 12_345, 'status' => 'sent',
            'triggered_by' => 'website', 'queued_at' => now(), 'sent_at' => now(),
        ]);

        $page()->assertInertia(fn (Assert $inertia) => $inertia->where('actions.correct', null));
    }

    public function test_the_client_settings_page_switches_the_cadence_through_the_api(): void
    {
        [$workspace, $owner, $company] = $this->reminderFixture();

        $this->actingAs($owner)->get(route('clients.settings', [$workspace, $company]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment_reminders.enabled', false)
                ->where('payment_reminders.update_href', route('agent-api.v1.clients.payment-reminders.update', [$workspace, $company->public_id], absolute: false))
                ->has('payment_reminders.steps', 4));
    }
}
