<?php

namespace App\Services\Engagement;

use App\Http\Middleware\EnsureServicePackagesEnabled;
use App\Models\ClientAgreement;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Support\Facades\Route;

/**
 * The agreement page's cancellation panel for owners and admins (CAN-4,
 * EDGE-9), finished on the server: a pending request with the dates it may
 * be approved on, a scheduled end and how to undo it, or the dates an end
 * may be scheduled on - each with the agent endpoint and the version to send.
 *
 * Every end date is one the server offers: those with the notice period, and
 * the earlier ones only waiving it allows, marked so. The browser chooses
 * among them and never works one out. Null while the feature is off, or in a
 * process that booted without its endpoints.
 */
final class OperatorCancellationPanel
{
    public function __construct(private readonly CancellationStates $states) {}

    /** @return array<string, mixed>|null */
    public function for(Workspace $workspace, ClientAgreement $agreement): ?array
    {
        // The endpoints exist only in a process that booted with the feature on.
        if (! EnsureServicePackagesEnabled::enabled() || ! Route::has('agent-api.v1.cancellation-requests.approve')) {
            return null;
        }
        $state = $this->states->one($workspace, $agreement);
        $options = self::options($state->offered(), $state->offered(true));
        $agreementVersion = AgentApiVersion::for($agreement);

        return [
            'earliest_end_on' => $state->earliest(),
            'pending' => $state->pending === null ? null : [
                'requested_end_on' => $state->pending->requested_end_on->toDateString(),
                'reason' => $state->pending->reason,
                'requested_at' => $state->pending->requested_at->toIso8601ZuluString(),
                'requested_by' => $state->forOperator()['pending_request']['requested_by'] ?? null,
                'version' => AgentApiVersion::for($state->pending),
                'options' => $options,
                'approve_href' => route('agent-api.v1.cancellation-requests.approve', [$workspace, $state->pending->public_id], absolute: false),
                'decline_href' => route('agent-api.v1.cancellation-requests.decline', [$workspace, $state->pending->public_id], absolute: false),
            ],
            'scheduled' => $state->scheduled === null ? null : [
                'ends_on' => $state->scheduled->ends_on->toDateString(),
                'previous_ends_on' => $state->scheduled->previous_ends_on?->toDateString(),
                'source' => $state->scheduled->source,
                'scheduled_at' => $state->scheduled->scheduled_at->toIso8601ZuluString(),
                'version' => $agreementVersion,
                'undo_href' => route('agent-api.v1.agreements.scheduled-end.undo', [$workspace, $agreement->public_id], absolute: false),
            ],
            // Scheduling an end directly: only while nothing is pending or scheduled.
            'schedule' => $state->pending !== null || $options === [] ? null : [
                'version' => $agreementVersion,
                'options' => $options,
                'href' => route('agent-api.v1.agreements.scheduled-end.store', [$workspace, $agreement->public_id], absolute: false),
            ],
        ];
    }

    /**
     * The dates offered with notice, then those only waiving it allows, in date order.
     *
     * @param  list<string>  $withNotice
     * @param  list<string>  $withoutNotice
     * @return list<array{value: string, waives_notice: bool}>
     */
    public static function options(array $withNotice, array $withoutNotice): array
    {
        $options = [];
        foreach ($withoutNotice as $date) {
            if (! in_array($date, $withNotice, true)) {
                $options[$date] = ['value' => $date, 'waives_notice' => true];
            }
        }
        foreach ($withNotice as $date) {
            $options[$date] = ['value' => $date, 'waives_notice' => false];
        }
        ksort($options);

        return array_values($options);
    }
}
