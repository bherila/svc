<?php

namespace App\Actions;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\Billing\InvoiceEmailService;
use App\Support\Concurrency\Locks;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place a client company is edited, for the web form and for MCP.
 *
 * Keyed presence, not values: only the attributes the caller actually sent are
 * written, so a caller that changes one setting cannot blank the ones it never
 * showed. The Manage form sends every field, so for it this is a full update;
 * MCP may send any subset. A key that is present and null is an erasure - the
 * billing address is the one field where that is meaningful - and is written as
 * one.
 *
 * Re-read under a row lock inside the transaction that writes, scoped to the
 * workspace the caller was authorized against. The router and the MCP resolver
 * bind by key alone, so an ownership check made on the bound instance and a
 * write made afterwards are two statements about a row nothing held still in
 * between: reparent the company after the check and a request authorized against
 * one tenant modifies a row now owned by another. Taking the lock and
 * re-asserting the workspace under it makes the check and the write one decision.
 */
class UpdateClientCompany
{
    public function __construct(
        private readonly InvoiceEmailService $emails,
        private readonly ClientActivityRecorder $activities,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  name, billing_email, is_active,
     *                                            automatic_invoice_email_enabled, automatic_invoice_email_delay_days
     *
     * @throws ValidationException when automatic delivery is enabled with nobody to send to
     */
    public function handle(Workspace $workspace, ClientCompany $company, array $attributes): ClientCompany
    {
        return DB::transaction(function () use ($workspace, $company, $attributes): ClientCompany {
            $disablingAutomaticDelivery = array_key_exists('automatic_invoice_email_enabled', $attributes)
                && ! (bool) $attributes['automatic_invoice_email_enabled'];
            // An update acquires row locks too. Lock every delivery this
            // request will cancel before the company, matching issuance's
            // invoice -> company order instead of hiding the reverse order in
            // a bulk UPDATE after the company lock.
            $pendingInvoiceIds = $disablingAutomaticDelivery
                ? ClientInvoice::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('client_company_id', $company->id)
                    ->whereIn('automatic_delivery_status', ['scheduled', 'held', 'failed'])
                    ->orderBy('id')
                    ->tap(Locks::forUpdate())
                    ->pluck('id')
                    ->all()
                : [];
            $locked = ClientCompany::query()
                ->whereKey($company->getKey())
                ->where('workspace_id', $workspace->id)
                ->tap(Locks::forUpdate())
                ->first();

            // Gone, or no longer this workspace's. 404 rather than 403 for the
            // same reason every other miss here does: a tenant learns nothing
            // about a record it cannot reach, including that it exists.
            abort_if($locked === null, 404);

            $billingEmail = array_key_exists('billing_email', $attributes)
                ? (is_string($attributes['billing_email']) ? $attributes['billing_email'] : null)
                : $locked->billing_email;
            $enabled = array_key_exists('automatic_invoice_email_enabled', $attributes)
                ? (bool) $attributes['automatic_invoice_email_enabled']
                : (bool) $locked->automatic_invoice_email_enabled;
            if ($enabled && $this->emails->suggestedRecipientsForCompany($locked, $billingEmail, true) === []) {
                throw ValidationException::withMessages([
                    'automatic_invoice_email_enabled' => 'Add a valid billing email or client portal recipient before enabling automatic invoice delivery.',
                ]);
            }

            $before = [
                'enabled' => (bool) $locked->automatic_invoice_email_enabled,
                'delay_days' => $locked->automatic_invoice_email_delay_days,
            ];

            $locked->update([
                'name' => $attributes['name'] ?? $locked->name,
                'billing_email' => $billingEmail,
                'automatic_invoice_email_enabled' => $enabled,
                'automatic_invoice_email_delay_days' => $enabled
                    ? (int) ($attributes['automatic_invoice_email_delay_days'] ?? $locked->automatic_invoice_email_delay_days)
                    : null,
                'is_active' => array_key_exists('is_active', $attributes)
                    ? (bool) $attributes['is_active']
                    : $locked->is_active,
            ]);

            $cancelledInvoices = 0;
            if (! $enabled && $pendingInvoiceIds !== []) {
                $cancelledInvoices = ClientInvoice::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('client_company_id', $locked->id)
                    ->whereIn('id', $pendingInvoiceIds)
                    ->whereIn('automatic_delivery_status', ['scheduled', 'held', 'failed'])
                    ->update([
                        'automatic_delivery_status' => 'cancelled',
                        'automatic_delivery_note' => 'Automatic client delivery was disabled.',
                        'lock_version' => DB::raw('lock_version + 1'),
                        'updated_at' => $locked->freshTimestamp(),
                    ]);
            }

            $after = [
                'enabled' => $enabled,
                'delay_days' => $locked->automatic_invoice_email_delay_days,
            ];
            if ($before !== $after) {
                $this->activities->record(
                    $workspace,
                    $locked,
                    'client.invoice_delivery_settings_updated',
                    $locked,
                    [
                        'before' => $before,
                        'after' => $after,
                        'cancelled_invoices' => $cancelledInvoices,
                    ],
                    occurrence: (string) str()->uuid(),
                );
            }

            return $locked;
        });
    }
}
