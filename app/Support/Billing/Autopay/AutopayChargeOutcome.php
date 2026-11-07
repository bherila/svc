<?php

declare(strict_types=1);

namespace App\Support\Billing\Autopay;

/** One invoice's line in an autopay run's report. */
final readonly class AutopayChargeOutcome
{
    public function __construct(
        public string $workspaceId,
        public string $invoiceId,
        public string $invoiceNumber,
        public AutopayChargeResult $result,
        public ?AutopayVerdict $verdict = null,
        public ?int $amount = null,
        public ?string $currency = null,
        public ?string $detail = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'invoice_id' => $this->invoiceId,
            'invoice_number' => $this->invoiceNumber,
            'result' => $this->result->value,
            'verdict' => $this->verdict?->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'detail' => $this->detail,
        ];
    }
}
