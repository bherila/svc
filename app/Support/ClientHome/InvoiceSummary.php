<?php

namespace App\Support\ClientHome;

use App\Models\ClientInvoice;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;

/** One invoice, as much of it as an at-a-glance line can carry. */
final class InvoiceSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $invoiceNumber,
        public readonly string $status,
        public readonly string $currency,
        public readonly ?string $issueDate,
        public readonly ?string $dueDate,
        public readonly int $totalAmount,
        public readonly int $paidAmount,
        public readonly int $balanceAmount,
        public readonly string $href,
    ) {}

    public static function fromInvoice(ClientInvoice $invoice, string $href): self
    {
        $facts = AgentInvoicePresenter::summary($invoice);

        return new self(id: $facts['id'], invoiceNumber: $facts['invoice_number'], status: $facts['status'],
            currency: $facts['currency'], issueDate: $facts['issue_date'], dueDate: $facts['due_date'],
            totalAmount: $facts['total_amount'], paidAmount: $facts['paid_amount'], balanceAmount: $facts['balance_amount'], href: $href);
    }

    /**
     * @return array{
     *     id: string, invoice_number: string, status: string, currency: string,
     *     issue_date: string|null, due_date: string|null,
     *     total_amount: int, paid_amount: int, balance_amount: int, href: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoiceNumber,
            'status' => $this->status,
            'currency' => $this->currency,
            'issue_date' => $this->issueDate,
            'due_date' => $this->dueDate,
            'total_amount' => $this->totalAmount,
            'paid_amount' => $this->paidAmount,
            'balance_amount' => $this->balanceAmount,
            'href' => $this->href,
        ];
    }
}
