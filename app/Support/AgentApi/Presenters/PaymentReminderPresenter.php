<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\InvoiceReminderLog;
use App\Models\Workspace;
use App\Services\Billing\Reminders\PaymentReminderRepository;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\Reminders\PaymentReminderPolicy;
use App\Support\Billing\Reminders\PaymentReminderRefusal;
use App\Support\Billing\Reminders\PaymentReminderStep;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Payment reminders as operators and agents read them (#438): whether a
 * person may send one now and with which link, where the client's cadence
 * stands for this invoice, and every reminder already sent.
 */
final class PaymentReminderPresenter
{
    public function __construct(
        private readonly PaymentReminderRepository $repository,
        private readonly PaymentReminderPolicy $policy,
    ) {}

    /** @return array<string, mixed> */
    public function invoice(Workspace $workspace, ClientInvoice $invoice): array
    {
        $facts = $this->repository->facts($workspace, $invoice);
        $manual = $this->policy->manual($facts);

        return [
            'invoice_id' => $invoice->public_id,
            'invoice_version' => AgentApiVersion::for($invoice),
            'manual' => [
                'available' => $manual->sends(),
                'refusal' => self::refusal($manual->refusal),
                'pay_link' => $manual->sends() ? $manual->payLink->value : null,
                'recipients' => $facts->recipients,
            ],
            'cadence' => [
                'enabled' => $facts->cadenceEnabledAt !== null,
                'enabled_at' => self::instant($facts->cadenceEnabledAt),
                'hold' => self::refusal($this->policy->cadenceHold($facts)),
                'schedule' => array_map(static fn (array $row): array => [
                    'step' => $row['step']->value,
                    'label' => $row['step']->label(),
                    'scheduled_on' => $row['scheduled_on']?->toDateString(),
                    'state' => $row['state'],
                ], $this->policy->schedule($facts)),
            ],
            'reminders' => $this->repository->history($invoice)
                ->map(fn (InvoiceReminderLog $reminder): array => $this->reminder($reminder))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function reminder(InvoiceReminderLog $reminder): array
    {
        return [
            'id' => $reminder->public_id,
            'origin' => $reminder->origin->value,
            'step' => $reminder->cadence_step?->value,
            'channel' => $reminder->channel,
            'pay_link' => $reminder->pay_link->value,
            'square_invoice_number' => $reminder->square_invoice_number,
            'recipients' => $reminder->recipients,
            'subject' => $reminder->subject,
            'currency' => $reminder->currency,
            'amount_due' => $reminder->amount_due,
            'due_on' => $reminder->due_on?->toDateString(),
            'status' => $reminder->status->value,
            'attempt_number' => $reminder->attempt_number,
            'triggered_by' => $reminder->triggered_by->value,
            'triggered_by_name' => $reminder->triggeredByUser?->name,
            'queued_at' => self::instant($reminder->queued_at),
            'sent_at' => self::instant($reminder->sent_at),
            'failed_at' => self::instant($reminder->failed_at),
            'next_attempt_at' => self::instant($reminder->next_attempt_at),
            'error_summary' => $reminder->error_summary,
        ];
    }

    /** @return array<string, mixed> */
    public function company(ClientCompany $company): array
    {
        return [
            'client_id' => $company->public_id,
            'version' => AgentApiVersion::for($company),
            'enabled' => $company->payment_reminders_enabled_at !== null,
            'enabled_at' => self::instant($company->payment_reminders_enabled_at),
            'steps' => array_map(static fn (PaymentReminderStep $step): array => ['step' => $step->value, 'label' => $step->label(), 'offset_days' => $step->offsetDays()], PaymentReminderStep::cadence()),
        ];
    }

    /** @return array{code: string, message: string}|null */
    private static function refusal(?PaymentReminderRefusal $refusal): ?array
    {
        return $refusal === null ? null : ['code' => $refusal->value, 'message' => $refusal->message()];
    }

    private static function instant(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
