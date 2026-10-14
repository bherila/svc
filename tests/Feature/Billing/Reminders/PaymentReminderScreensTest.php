<?php

namespace Tests\Feature\Billing\Reminders;

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
