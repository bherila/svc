<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceKind;

final class AgentInvoicePresenter
{
    /** Safe scalar fields common to list rows and the canonical resource; no relation reads.
     * @return array{id:string,invoice_number:string,status:string,total_amount:int,paid_amount:int,balance_amount:int,currency:string,issue_date:?string,due_date:?string} */
    public static function summary(ClientInvoice $invoice): array
    {
        return ['id' => $invoice->public_id, 'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status, 'total_amount' => $invoice->total_amount,
            'paid_amount' => $invoice->paid_amount, 'balance_amount' => $invoice->balance_amount,
            'currency' => $invoice->currency, 'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString()];
    }

    /** @return array{service_period_start:?string,service_period_end:?string} */
    private static function servicePeriod(ClientInvoice $invoice): array
    {
        return ['service_period_start' => $invoice->service_period_start?->toDateString(),
            'service_period_end' => $invoice->service_period_end?->toDateString()];
    }

    /** Client-safe invoice facts, without manager capabilities or internal notes.
     * @return array<string, mixed> */
    public static function portalDetails(ClientInvoice $invoice): array
    {
        return self::summary($invoice) + self::servicePeriod($invoice) + [
            'subtotal_amount' => $invoice->subtotal_amount,
            'tax_amount' => $invoice->tax_amount,
        ];
    }

    /** @return array{description:string,unit_amount:int,total_amount:int} */
    private static function lineAmounts(ClientInvoiceLine $line): array
    {
        return ['description' => $line->description, 'unit_amount' => $line->unit_amount,
            'total_amount' => $line->total_amount];
    }

    /** Existing browser quantities are numeric; the API retains decimal strings.
     * @return array<string, mixed> */
    public static function portalLine(ClientInvoiceLine $line): array
    {
        return ['id' => $line->public_id] + self::lineAmounts($line) + [
            'quantity' => (float) $line->quantity,
            'hours' => $line->hours === null ? null : (float) $line->hours,
            'line_date' => $line->line_date?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    public function present(Workspace $workspace, ClientInvoice $invoice, bool $includeNotes = false): array
    {
        $payload = [
            ...self::summary($invoice),
            'company_id' => $invoice->clientCompany->public_id,
            'company_name' => $invoice->clientCompany->name,
            ...self::servicePeriod($invoice),
            'invoice_kind' => $invoice->invoiceKindValue(),
            // What `invoices.update_draft` accepts (#349): a generated draft
            // is regenerated, never edited by hand.
            'editable' => $invoice->status === 'draft' && $invoice->invoiceKindValue() === InvoiceKind::AdHoc->value,
            'linked_time_state' => $this->linkedTimeState($invoice),
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
            ...self::lineAmounts($line),
            'quantity' => (string) $line->quantity,
            'tax_amount' => $line->tax_amount,
        ];
    }

    /**
     * The draft invoices.generate_period would write, read inside the
     * preview's transaction before it is rolled back.
     *
     * @param  list<array{change: 'created'|'updated'|'deleted', invoice: ClientInvoice}>  $others  the company's other invoices the same generation changed
     * @return array<string, mixed>
     */
    public function periodPreview(Workspace $workspace, ClientCompany $company, ClientAgreement $agreement, string $agreementVersion, ClientInvoice $draft, array $others): array
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
            'other_invoices' => array_map(static fn (array $other): array => [
                // A created invoice is rolled back, so its id names nothing.
                'id' => $other['change'] === 'created' ? null : $other['invoice']->public_id,
                'change' => $other['change'],
                'invoice_kind' => $other['invoice']->invoiceKindValue(),
                'service_period_start' => $other['invoice']->service_period_start?->toDateString(),
                'service_period_end' => $other['invoice']->service_period_end?->toDateString(),
                'currency' => $other['invoice']->currency,
                'total_amount' => $other['invoice']->total_amount,
            ], $others),
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
