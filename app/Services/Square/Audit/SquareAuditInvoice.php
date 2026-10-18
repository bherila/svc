<?php

namespace App\Services\Square\Audit;

use App\Services\Square\Mirror\SquareSeriesSummary;

/**
 * One mirrored Square invoice, with the svc invoice it is actively linked to
 * when it is (#438). Dates are calendar days (Y-m-d).
 */
final readonly class SquareAuditInvoice
{
    /** @param  list<string>  $paymentIds */
    public function __construct(
        public string $squareInvoiceId,
        public ?string $invoiceNumber,
        public ?string $seriesKey,
        public ?int $seriesOccurrence,
        public ?string $customerId,
        public ?string $recipientName,
        public ?string $locationId,
        public string $status,
        public ?string $currency,
        public ?int $totalAmount,
        public ?int $paidAmount,
        public ?string $dueOn,
        public ?string $scheduledOn,
        public bool $autopay,
        public array $paymentIds,
        public ?SquareAuditLinkedInvoice $linked,
    ) {}

    /** The day the invoice falls due, else the day Square schedules it. */
    public function date(): ?string
    {
        return $this->dueOn ?? $this->scheduledOn;
    }

    /**
     * The member shape {@see SquareSeriesSummary::of()} reads.
     *
     * @return array{series_occurrence: int|null, due_on: string|null, scheduled_on: string|null, status: string, currency: string|null, total_amount: int|null, paid_amount: int|null, autopay: bool, square_customer_id: string|null, recipient_name: string|null, square_location_id: string|null}
     */
    public function seriesMember(): array
    {
        return [
            'series_occurrence' => $this->seriesOccurrence,
            'due_on' => $this->dueOn,
            'scheduled_on' => $this->scheduledOn,
            'status' => $this->status,
            'currency' => $this->currency,
            'total_amount' => $this->totalAmount,
            'paid_amount' => $this->paidAmount,
            'autopay' => $this->autopay,
            'square_customer_id' => $this->customerId,
            'recipient_name' => $this->recipientName,
            'square_location_id' => $this->locationId,
        ];
    }
}
