<?php

namespace App\Support\Engagement\Cancellation;

use App\Models\AgreementCancellationRequest;
use App\Models\AgreementScheduledTermination;
use App\Models\ClientAgreement;
use App\Services\Engagement\CancellationRecords;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Engagement\CancellationDates;
use Carbon\CarbonInterface;

/**
 * Where one agreement's cancellation stands, as the server says it to every
 * reader (CAN-2, CAN-4, EDGE-9): its terms, the end dates offered today, any
 * pending request and any scheduled end. Built from the agreement and its two
 * open records already loaded, with no database, so a page of agreements is
 * presented from two bounded reads ({@see CancellationRecords::openFor()}).
 *
 * Dates are offered only while an end may be scheduled: an active agreement
 * that bills in periods and has no end scheduled. The browser never works a
 * date out; it shows these.
 */
final readonly class CancellationState
{
    public function __construct(
        public ClientAgreement $agreement,
        public CancellationTerms $terms,
        public ?AgreementCancellationRequest $pending,
        public ?AgreementScheduledTermination $scheduled,
        public CarbonInterface $today,
    ) {}

    public static function of(ClientAgreement $agreement, ?AgreementCancellationRequest $pending, ?AgreementScheduledTermination $scheduled, CarbonInterface $today): self
    {
        return new self($agreement, CancellationTerms::of($agreement), $pending, $scheduled, $today);
    }

    /** Whether an end may be scheduled now, by anyone. */
    public function schedulable(): bool
    {
        return CancellationRules::scheduleRefusal((string) $this->agreement->status, $this->terms->recurring(), $this->scheduled !== null) === null;
    }

    /**
     * The end dates offered today: with the notice period, or with it waived.
     *
     * @return list<string>
     */
    public function offered(bool $waiveNotice = false): array
    {
        if (! $this->schedulable()) {
            return [];
        }

        $dates = /** @infection-ignore-all Equivalent: offer() is null only for an agreement that does not bill in periods, which schedulable() has already refused, so `?->` and `->` never differ here. */ $this->terms->offer($this->today, $waiveNotice, null, new CancellationDates)?->offeredDateStrings();

        return $dates ?? [];
    }

    /** The earliest end with notice, or null when none can be offered now. */
    public function earliest(): ?string
    {
        return $this->offered()[0] ?? null;
    }

    /**
     * The owner's and admin's view (`agreements.get` and the agreement page).
     *
     * @return array{earliest_end_on: string|null, offered_end_dates: list<string>, offered_end_dates_without_notice: list<string>, pending_request: array<string, mixed>|null, scheduled_end: array<string, mixed>|null}
     */
    public function forOperator(): array
    {
        return [
            'earliest_end_on' => $this->earliest(),
            'offered_end_dates' => $this->offered(),
            'offered_end_dates_without_notice' => $this->offered(true),
            'pending_request' => $this->pending === null ? null : [
                'id' => $this->pending->public_id,
                'version' => AgentApiVersion::for($this->pending),
                'requested_end_on' => $this->pending->requested_end_on->toDateString(),
                'reason' => $this->pending->reason,
                'requested_at' => $this->pending->requested_at->toIso8601ZuluString(),
                'requested_by' => $this->pending->relationLoaded('requestedBy') ? $this->pending->requestedBy?->name : null,
            ],
            'scheduled_end' => $this->scheduledEnd(),
        ];
    }

    /**
     * The client's view (`client_services.*` and the portal): the agreement's
     * own terms, the dates they could choose, and what is pending or
     * scheduled. Dates are offered only where the portal mode lets a client
     * cancel; whether this reader may act is the portal's to say.
     *
     * @return array{mode: string, notice_days: int, minimum_term_months: int|null, earliest_end_on: string|null, offered_end_dates: list<string>, pending_request: array<string, mixed>|null, scheduled_end: array<string, mixed>|null}
     */
    public function forClient(): array
    {
        $refusal = CancellationRules::clientRefusal($this->terms->mode, (string) $this->agreement->status, $this->terms->recurring(), $this->scheduled !== null, $this->pending !== null);
        $offered = $refusal === null ? $this->offered() : [];

        return [
            'mode' => $this->terms->mode->value,
            'notice_days' => $this->terms->noticeDays,
            'minimum_term_months' => $this->terms->minimumTermMonths,
            'earliest_end_on' => $offered[0] ?? null,
            'offered_end_dates' => $offered,
            'pending_request' => $this->pending === null ? null : [
                'id' => $this->pending->public_id,
                'requested_end_on' => $this->pending->requested_end_on->toDateString(),
                'reason' => $this->pending->reason,
                'requested_at' => $this->pending->requested_at->toIso8601ZuluString(),
            ],
            'scheduled_end' => $this->scheduledEnd(),
        ];
    }

    /** @return array{id: string, ends_on: string, previous_ends_on: string|null, source: string, scheduled_at: string}|null */
    private function scheduledEnd(): ?array
    {
        return $this->scheduled === null ? null : [
            'id' => $this->scheduled->public_id,
            'ends_on' => $this->scheduled->ends_on->toDateString(),
            'previous_ends_on' => $this->scheduled->previous_ends_on?->toDateString(),
            'source' => $this->scheduled->source,
            'scheduled_at' => $this->scheduled->scheduled_at->toIso8601ZuluString(),
        ];
    }
}
