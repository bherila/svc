<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use App\Support\Billing\InvoiceStatus;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Support\Facades\DB;

final class InvoiceEmailService
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** @param list<string> $recipients */
    public function queue(ClientInvoice $invoice, array $recipients, ?Workspace $workspace = null): ClientInvoiceEmailDelivery
    {
        if ($workspace !== null && ! $this->workspaceAuthorization->isOwnedBy($workspace, $invoice)) {
            throw new DomainException('Invoice does not belong to this workspace.');
        }
        if (! in_array($invoice->status, InvoiceStatus::collectible(), true)) {
            throw new DomainException('Only collectible issued invoices can be emailed.');
        }
        $recipients = array_values(array_unique(array_filter(array_map('trim', $recipients))));
        if ($recipients === []) {
            throw new DomainException('At least one email recipient is required.');
        }
        foreach ($recipients as $recipient) {
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw new DomainException('Every invoice recipient must be a valid email address.');
            }
        }

        $delivery = DB::transaction(function () use ($invoice, $recipients): ClientInvoiceEmailDelivery {
            $delivery = $invoice->emailDeliveries()->create([
                'workspace_id' => $invoice->workspace_id,
                'recipients' => $recipients,
                'subject' => 'Invoice '.$invoice->invoice_number,
                'status' => 'pending',
                'queued_at' => $this->clock->now($invoice->workspace),
            ]);
            $invoice->advanceAgentRevision();
            dispatch(new SendInvoiceEmailJob($invoice->id, $delivery->id))->afterCommit();

            return $delivery;
        });

        return $delivery->fresh();
    }
}
