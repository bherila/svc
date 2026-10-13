<?php

namespace App\Services\Square\Mirror;

use App\Models\SquareInvoiceSnapshot;
use App\Models\SquareSyncRun;
use DateTimeInterface;

/**
 * Mirror rows as the API and the operator screen show them (#438). Every
 * field is one the sync stored from SquarePresenter's allow-list.
 */
final class SquareMirrorPresenter
{
    /** Reminders per invoice: Square keeps at most ten per payment request. */
    public const int MAX_REMINDERS = 50;

    /** @return array<string, mixed> */
    public function run(SquareSyncRun $run): array
    {
        return [
            'id' => $run->public_id,
            'trigger' => $run->trigger,
            'status' => $run->status,
            'square_version' => $run->api_version,
            'requested_at' => self::instant($run->created_at),
            'started_at' => self::instant($run->started_at),
            'finished_at' => self::instant($run->finished_at),
            'locations_seen' => $run->locations_seen,
            'customers_seen' => $run->customers_seen,
            'customers_changed' => $run->customers_changed,
            'invoices_seen' => $run->invoices_seen,
            'invoices_changed' => $run->invoices_changed,
            'payments_resolved' => $run->payments_resolved,
            'high_water_mark' => self::instant($run->high_water_mark),
            'truncated' => $run->truncated,
            'error_code' => $run->error_code,
            'error' => self::error($run->error_code),
        ];
    }

    /** @return array<string, mixed> */
    public function invoice(SquareInvoiceSnapshot $invoice): array
    {
        $reminders = [];
        foreach ($invoice->payment_requests as $request) {
            foreach (is_array($request['reminders'] ?? null) ? $request['reminders'] : [] as $reminder) {
                if (is_array($reminder)) {
                    $reminders[] = [
                        'relative_scheduled_days' => is_int($reminder['relative_scheduled_days'] ?? null) ? $reminder['relative_scheduled_days'] : null,
                        'status' => is_string($reminder['status'] ?? null) ? $reminder['status'] : null,
                        'sent_at' => is_string($reminder['sent_at'] ?? null) ? $reminder['sent_at'] : null,
                    ];
                }
            }
        }

        return [
            'square_invoice_id' => $invoice->square_invoice_id,
            'invoice_number' => $invoice->invoice_number,
            'series_key' => $invoice->series_key,
            'series_occurrence' => $invoice->series_occurrence,
            'subscription_id' => $invoice->square_subscription_id,
            'location_id' => $invoice->square_location_id,
            'order_id' => $invoice->square_order_id,
            'customer_id' => $invoice->square_customer_id,
            'recipient_name' => $invoice->recipient_name,
            'title' => $invoice->title,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'total_amount' => $invoice->total_amount,
            'paid_amount' => $invoice->paid_amount,
            'next_payment_amount' => $invoice->next_payment_amount,
            'due_on' => $invoice->due_on?->toDateString(),
            'scheduled_at' => self::instant($invoice->scheduled_at),
            'sale_or_service_date' => $invoice->sale_or_service_date?->toDateString(),
            'created_at' => self::instant($invoice->square_created_at),
            'updated_at' => self::instant($invoice->square_updated_at),
            'public_url' => $invoice->public_url,
            'autopay' => $invoice->autopay,
            // Square's own reminder schedule, so svc does not duplicate it.
            'reminders' => array_slice($reminders, 0, self::MAX_REMINDERS),
            'payment_ids' => $invoice->payment_ids,
            'last_seen_at' => self::instant($invoice->last_seen_at),
        ];
    }

    /** Why a run failed, for a person; null when it did not. */
    public static function error(?string $code): ?string
    {
        return match ($code) {
            null => null,
            'NOT_CONNECTED' => 'Square is not connected to this workspace.',
            'SERVICE_UNAVAILABLE' => 'Square was unavailable. The next sync will try again.',
            'RATE_LIMITED' => 'Square limited svc\'s requests. The next sync will try again.',
            'UNREADABLE' => 'Square returned a response svc cannot read.',
            'ABANDONED' => 'The sync stopped before it finished.',
            'INTERNAL' => 'The sync failed inside svc.',
            default => "Square refused a request ({$code}). An operator may need to check the connection.",
        };
    }

    public static function instant(?DateTimeInterface $at): ?string
    {
        return $at?->format('Y-m-d\TH:i:s\Z');
    }
}
