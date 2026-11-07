<?php

namespace App\Services\Payments;

use App\Models\WorkspacePaymentConnection;
use App\Services\Billing\StripeWebhookService;
use App\Support\Payments\WebhookHealthStatus;

/**
 * The stored webhook check as the Payments page shows it: finished sentences,
 * a severity, the endpoint URL (managers only) and the one fix that applies,
 * with its URL, or null. The browser prints this and decides nothing.
 */
final class WebhookHealthPresenter
{
    public function __construct(private readonly StripeWebhookHealthService $health) {}

    /**
     * @return array{severity: string, summary: string, detail: string|null, missing: list<string>, endpoint_url: string|null, checked_at: string|null, check_url: string|null, fix: array{action: string, label: string, confirm_title: string, confirm_body: string, url: string}|null}|null
     */
    public function present(WorkspacePaymentConnection $row, bool $manages, ?string $checkUrl, ?string $fixUrl): ?array
    {
        if (! $this->health->checkable($row)) {
            return null;
        }
        $status = $row->webhook_check_status === null ? null : WebhookHealthStatus::tryFrom($row->webhook_check_status);
        $missing = array_values(array_filter($row->webhook_check_missing ?? [], is_string(...)));
        [$summary, $detail] = $status === null
            ? ['Webhook not checked yet', 'SVC has not looked at this account\'s webhook endpoint. It checks daily, and when you press Check again.']
            : $this->words($status, $missing);

        return [
            'severity' => $status?->severity() ?? 'unknown',
            'summary' => $summary,
            'detail' => $detail,
            'missing' => $status === WebhookHealthStatus::MissingEvents ? $missing : [],
            'endpoint_url' => $manages ? $this->health->expectedUrls($row)[0] : null,
            'checked_at' => $row->webhook_checked_at?->utc()->toIso8601String(),
            'check_url' => $manages ? $checkUrl : null,
            'fix' => $manages && $fixUrl !== null ? $this->fix($status, $missing, $fixUrl) : null,
        ];
    }

    /**
     * @param  list<string>  $missing
     * @return array{0: string, 1: string|null}
     */
    private function words(WebhookHealthStatus $status, array $missing): array
    {
        return match ($status) {
            WebhookHealthStatus::Healthy => ['Webhook: all '.count(StripeWebhookService::REQUIRED_EVENTS).' events subscribed', null],
            WebhookHealthStatus::MissingEvents => [
                'Missing: '.implode(', ', $missing),
                'Stripe is not telling SVC about these. Payments, refunds or saved cards can fall out of step until the endpoint is subscribed to them.',
            ],
            WebhookHealthStatus::Disabled => [
                'Webhook endpoint is disabled in Stripe',
                'Stripe is not sending any events to SVC. In the Stripe Dashboard, open the endpoint at the URL below and enable it, then check again.',
            ],
            WebhookHealthStatus::NotFound => [
                'No webhook endpoint found for this connection',
                'Stripe has no enabled endpoint at the URL below, so payments, refunds and payouts are not reaching SVC.',
            ],
            WebhookHealthStatus::ModeMismatch => [
                'Webhook endpoint is in the other mode than this key',
                'The endpoint at the URL below is a test-mode endpoint for a live key, or the reverse. Events from the key\'s mode are not reaching SVC.',
            ],
            WebhookHealthStatus::PermissionDenied => [
                'SVC cannot read this key\'s webhook endpoints',
                'In Stripe, edit the restricted key and set Webhook Endpoints to Read (Write if you want SVC to be able to fix the endpoint), then check again.',
            ],
            WebhookHealthStatus::KeyRejected => [
                'Stripe did not accept the saved key',
                'Replace the key with a valid restricted key, then check again.',
            ],
            WebhookHealthStatus::Unreachable => [
                'The webhook could not be checked',
                'SVC got no usable answer from Stripe. It will try again tomorrow, or press Check again.',
            ],
        };
    }

    /**
     * @param  list<string>  $missing
     * @return array{action: string, label: string, confirm_title: string, confirm_body: string, url: string}|null
     */
    private function fix(?WebhookHealthStatus $status, array $missing, string $url): ?array
    {
        return match ($status) {
            WebhookHealthStatus::MissingEvents => [
                'action' => StripeWebhookHealthService::ADD_EVENTS,
                'label' => 'Add missing events',
                'confirm_title' => 'Add the missing events to the Stripe webhook?',
                'confirm_body' => 'SVC will subscribe this workspace\'s endpoint to '.implode(', ', $missing).'. Events it is already subscribed to are kept, and no other endpoint is touched. The restricted key needs Webhook Endpoints: Write.',
                'url' => $url,
            ],
            WebhookHealthStatus::NotFound => [
                'action' => StripeWebhookHealthService::CREATE,
                'label' => 'Create endpoint',
                'confirm_title' => 'Create the Stripe webhook endpoint?',
                'confirm_body' => 'SVC will create an endpoint at the URL shown, subscribed to all events it needs. Stripe gives a new signing secret for it, which SVC stores encrypted in place of the old one. No other endpoint is touched. The restricted key needs Webhook Endpoints: Write.',
                'url' => $url,
            ],
            WebhookHealthStatus::Healthy, WebhookHealthStatus::Disabled, WebhookHealthStatus::ModeMismatch, WebhookHealthStatus::PermissionDenied, WebhookHealthStatus::KeyRejected, WebhookHealthStatus::Unreachable, null => null,
        };
    }
}
