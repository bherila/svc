<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceKind;
use Illuminate\Support\Collection;

final class AgentInvoicePresenter
{
    /** @return array<string, mixed> */
    public function present(Workspace $workspace, ClientInvoice $invoice, bool $includeNotes = false): array
    {
        $payload = [
            'id' => $invoice->public_id,
            'company_id' => $invoice->clientCompany->public_id,
            'company_name' => $invoice->clientCompany->name,
            'service_period_start' => $invoice->service_period_start?->toDateString(),
            'service_period_end' => $invoice->service_period_end?->toDateString(),
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'invoice_kind' => $invoice->invoiceKindValue(),
            // What `invoices.update_draft` accepts (#349): a generated draft
            // is regenerated, never edited by hand.
            'editable' => $invoice->status === 'draft' && $invoice->invoiceKindValue() === InvoiceKind::AdHoc->value,
            'linked_time_state' => $this->linkedTimeState($invoice),
            'currency' => $invoice->currency,
            'total_amount' => $invoice->total_amount,
            'paid_amount' => $invoice->paid_amount,
            'balance_amount' => $invoice->balance_amount,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'scheduled_issue_date' => $invoice->scheduled_issue_date?->toDateString(),
            'automatic_delivery_status' => $includeNotes ? $invoice->automatic_delivery_status : null,
            'automatic_delivery_due_at' => $includeNotes ? $invoice->automatic_delivery_due_at?->toISOString() : null,
            'version' => AgentApiVersion::for($invoice),
            'web_url' => rtrim((string) config('app.url'), '/').route('svc.billing.invoices.show', [$workspace, $invoice], absolute: false),
            'pdf_url' => rtrim((string) config('app.url'), '/').route('svc.billing.invoices.pdf', [$workspace, $invoice], absolute: false),
        ];

        if ($includeNotes) {
            $payload['notes'] = $invoice->notes;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function mutation(Workspace $workspace, ClientInvoice $invoice): array
    {
        return [
            'id' => $invoice->public_id,
            'status' => $invoice->status,
            'linked_time_state' => $this->linkedTimeState($invoice),
            'invoice_number' => $invoice->invoice_number,
            'version' => AgentApiVersion::for($invoice),
            'web_url' => rtrim((string) config('app.url'), '/').route('svc.billing.invoices.show', [$workspace, $invoice], absolute: false),
        ];
    }

    /**
     * One line as invoices.get returns it, less its id. The preview has no
     * line ids to give - they are rolled back - so both reads share this and
     * a preview line is an invoices.get line with nothing else different.
     *
     * @return array{project_id: string|null, type: string, description: string, quantity: string, unit_amount: int, tax_amount: int, total_amount: int}
     */
    public function line(ClientInvoiceLine $line): array
    {
        return [
            'project_id' => $line->project?->public_id,
            'type' => $line->type,
            'description' => $line->description,
            'quantity' => (string) $line->quantity,
            'unit_amount' => $line->unit_amount,
            'tax_amount' => $line->tax_amount,
            'total_amount' => $line->total_amount,
        ];
    }

    /**
     * The draft invoices.generate_period would write, read inside the
     * preview's transaction before it is rolled back.
     *
     * @param  Collection<int, ClientInvoice>  $others  other invoices the same generation created
     * @return array<string, mixed>
     */
    public function periodPreview(Workspace $workspace, ClientCompany $company, ClientAgreement $agreement, string $agreementVersion, ClientInvoice $draft, Collection $others): array
    {
        $lines = $draft->lines()->where('workspace_id', $workspace->id)
            ->with(['project' => fn ($projects) => $projects->where('workspace_id', $workspace->id)])->get();

        return [
            'agreement_id' => $agreement->public_id,
            'agreement_version' => $agreementVersion,
            'company_id' => $company->public_id,
            'company_name' => $company->name,
            'period_start' => $draft->cycle_start?->toDateString(),
            'invoice_kind' => $draft->invoiceKindValue(),
            'service_period_start' => $draft->service_period_start?->toDateString(),
            'service_period_end' => $draft->service_period_end?->toDateString(),
            'currency' => $draft->currency,
            'total_amount' => $draft->total_amount,
            'issue_date' => $draft->issue_date?->toDateString(),
            'due_date' => $draft->due_date?->toDateString(),
            'lines' => $lines->map(fn (ClientInvoiceLine $line): array => $this->line($line))->values()->all(),
            'other_invoices' => $others->map(static fn (ClientInvoice $other): array => [
                'invoice_kind' => $other->invoiceKindValue(),
                'service_period_start' => $other->service_period_start?->toDateString(),
                'service_period_end' => $other->service_period_end?->toDateString(),
                'currency' => $other->currency,
                'total_amount' => $other->total_amount,
            ])->values()->all(),
        ];
    }

    private function linkedTimeState(ClientInvoice $invoice): string
    {
        return match ($invoice->status) {
            'draft' => 'reserved',
            'void' => 'released',
            default => 'consumed',
        };
    }
}
