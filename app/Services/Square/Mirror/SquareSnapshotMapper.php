<?php

namespace App\Services\Square\Mirror;

use App\Services\Square\SquareClient;
use App\Services\Square\SquarePresenter;
use App\Services\Square\SquareRequestFailed;
use App\Support\Square\SquareIdentifiers;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Square records reduced to mirror rows (#438). Database-free.
 *
 * Every record goes through {@see SquarePresenter} first, so the mirror holds
 * exactly the allow-listed fields the pass-through returns - never a card
 * fingerprint, a stored-card id, an address or a note - and only then into
 * columns. A record without a well-formed Square id (or, for an invoice, a
 * status) is skipped rather than stored half-understood.
 *
 * The fingerprint is a hash of the presented record. The sync writes a row
 * only when it changes, so an unchanged Square account costs one read per row
 * and no writes.
 */
final class SquareSnapshotMapper
{
    private const string DATE = '/^(\d{4})-(\d{2})-(\d{2})$/D';

    private const string STATUS = '/^[A-Z][A-Z_]{0,31}$/D';

    /** Tenders read per order at most, as the payment reconciler bounds them. */
    private const int MAX_TENDERS = 100;

    /** Invoice statuses under which Square may hold a payment. */
    public const array PAYMENT_STATUSES = ['PAID', 'PARTIALLY_PAID', 'PARTIALLY_REFUNDED', 'REFUNDED', 'PAYMENT_PENDING'];

    /** The tender rules payment ids are read by; see {@see paymentIds()}. */
    public const string TENDER_RULES = 't2';

    /** The one card tender state in which money has moved. */
    private const string CAPTURED = 'CAPTURED';

    /** A card tender that may still be captured, or voided. */
    private const string AUTHORIZED = 'AUTHORIZED';

    public function __construct(private readonly SquarePresenter $presenter) {}

    /**
     * @param  array<mixed>  $invoice  as Square sent it
     * @return array<string, mixed>|null
     */
    public function invoice(array $invoice): ?array
    {
        $presented = $this->presenter->invoice($invoice);
        $id = is_string($presented['id']) && SquareIdentifiers::isInvoiceId($presented['id']) ? $presented['id'] : null;
        $status = is_string($presented['status']) && preg_match(self::STATUS, $presented['status']) === 1 ? $presented['status'] : null;
        if ($id === null || $status === null) {
            return null;
        }
        /** @var list<array<string, mixed>> $requests */
        $requests = $presented['payment_requests'];
        $money = self::money($requests, $presented['next_payment_amount_money']);
        $recipient = is_array($presented['primary_recipient']) ? $presented['primary_recipient'] : [];

        return [
            'square_invoice_id' => $id,
            'square_version' => is_int($presented['version']) && $presented['version'] >= 0 ? $presented['version'] : null,
            'square_location_id' => self::id($presented['location_id']),
            'square_order_id' => self::id($presented['order_id']),
            'square_customer_id' => self::id($recipient['customer_id'] ?? null),
            'square_subscription_id' => self::id($presented['subscription_id']),
            'invoice_number' => $presented['invoice_number'],
            'series_key' => $presented['series_key'],
            'series_occurrence' => $presented['series_occurrence'],
            'title' => $presented['title'],
            'recipient_name' => self::name($recipient),
            'status' => $status,
            ...$money,
            'due_on' => self::dueOn($requests),
            'scheduled_at' => self::timestamp($presented['scheduled_at']),
            'sale_or_service_date' => self::date($presented['sale_or_service_date']),
            'square_created_at' => self::timestamp($presented['created_at']),
            'square_updated_at' => self::timestamp($presented['updated_at']),
            'public_url' => $presented['public_url'],
            'autopay' => array_any($requests, static fn (array $request): bool => ($request['automatic_payment_source'] ?? null) === 'CARD_ON_FILE'),
            'payment_requests' => json_encode($requests, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'fingerprint' => self::fingerprint($presented),
        ];
    }

    /**
     * @param  array<mixed>  $customer  as Square sent it
     * @return array<string, mixed>|null
     */
    public function customer(array $customer): ?array
    {
        $presented = $this->presenter->customer($customer);
        $id = self::id($presented['id']);
        if ($id === null) {
            return null;
        }

        return [
            'square_customer_id' => $id,
            'square_version' => is_int($presented['version']) && $presented['version'] >= 0 ? $presented['version'] : null,
            'given_name' => $presented['given_name'],
            'family_name' => $presented['family_name'],
            'company_name' => $presented['company_name'],
            'email_address' => $presented['email_address'],
            'phone_number' => $presented['phone_number'],
            'reference_id' => $presented['reference_id'],
            'square_created_at' => self::timestamp($presented['created_at']),
            'square_updated_at' => self::timestamp($presented['updated_at']),
            'fingerprint' => self::fingerprint($presented),
        ];
    }

    /**
     * @param  array<mixed>  $location  as Square sent it
     * @return array<string, mixed>|null
     */
    public function location(array $location): ?array
    {
        $presented = $this->presenter->location($location);
        $id = self::id($presented['id']);
        if ($id === null) {
            return null;
        }

        return [
            'square_location_id' => $id,
            'name' => $presented['name'],
            'business_name' => $presented['business_name'],
            'status' => $presented['status'],
            'currency' => is_string($presented['currency']) && preg_match('/^[A-Z]{3}$/D', $presented['currency']) === 1 ? $presented['currency'] : null,
            'timezone' => $presented['timezone'],
            'fingerprint' => self::fingerprint($presented),
        ];
    }

    /**
     * The payment ids resolved for an invoice are good while its status and
     * paid amount are what they were then; either changing means a payment or
     * refund happened since and the order is read again. The basis also names
     * the rules tenders were read by, so a change to those rules re-reads
     * every order once.
     */
    public static function paymentIdsBasis(string $status, ?int $paidAmount): string
    {
        return $status.'|'.($paidAmount ?? '').'|'.self::TENDER_RULES;
    }

    /**
     * The basis an invoice is stamped with when its order could not be read
     * into payment ids for this state - more tenders than the reconciler
     * accepts. It is never current, so the audit stays incomplete, but the
     * lookup queue skips it until the invoice changes, so one such order
     * cannot take every lookup slot of every run.
     */
    public static function unresolvableBasis(string $status, ?int $paidAmount): string
    {
        return self::paymentIdsBasis($status, $paidAmount).'|x';
    }

    /**
     * Whether a mirrored invoice's payment ids are resolved for what it is
     * now. An invoice with nothing paid has none to resolve; one whose paid
     * amount is unknown may hold a payment when its status says so, and is
     * read like any other; and one that may hold a payment but has no order
     * cannot be resolved at all.
     */
    public static function paymentIdsCurrent(string $status, ?int $paidAmount, ?string $orderId, ?string $basis): bool
    {
        $mayHoldPayment = $paidAmount === null ? in_array($status, self::PAYMENT_STATUSES, true) : $paidAmount > 0;

        // Without an order there is nothing to read the payments from, so one
        // that may hold a payment stays unresolved rather than counted empty.
        return ! $mayHoldPayment || ($orderId !== null && $basis === self::paymentIdsBasis($status, $paidAmount));
    }

    /**
     * Payment ids from an order's tenders, as Square sent the order.
     *
     * A tender names its payment in `payment_id`, or only by its own `id` -
     * the reconciler reads both the same way. A card tender counts once it is
     * captured: an authorization may still be voided without the invoice
     * changing, and a voided or failed tender took no money. A capture moves
     * the invoice's paid amount, so the order is read again then.
     *
     * Null when the order has more tenders than the reconciler accepts, or
     * holds an open authorization while the invoice's paid amount is unknown
     * (`$amountKnown` false): the ids stay unresolved rather than recorded
     * short. A tender list that is
     * not a list, or a tender without a readable id, is unreadable, as the
     * reconciler finds it, and the sync fails and retries rather than
     * recording it short or empty.
     *
     * @param  array<mixed>  $order
     * @return list<string>|null
     *
     * @throws SquareRequestFailed when the tenders are not a list of readable tenders
     */
    public function paymentIds(array $order, bool $amountKnown = true): ?array
    {
        $ids = [];
        $tenders = $order['tenders'] ?? [];
        if (! is_array($tenders) || ! array_is_list($tenders)) {
            throw SquareRequestFailed::unreadable();
        }
        if (count($tenders) > self::MAX_TENDERS) {
            return null;
        }
        foreach ($tenders as $tender) {
            // As the reconciler reads them: a tender that is not one, or has
            // no readable id, makes the order unreadable rather than shorter.
            $id = is_array($tender) ? (self::id($tender['payment_id'] ?? null) ?? self::id($tender['id'] ?? null)) : null;
            if ($id === null) {
                throw SquareRequestFailed::unreadable();
            }
            $card = $tender['card_details'] ?? [];
            $state = is_array($card) ? ($card['status'] ?? self::CAPTURED) : self::CAPTURED;
            // A later capture is seen by the paid amount changing; with no
            // known amount nothing would ever show it, so an open
            // authorization leaves the ids unresolved.
            if ($state === self::AUTHORIZED && ! $amountKnown) {
                return null;
            }
            if ($state !== self::CAPTURED) {
                continue;
            }
            if (! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Totals over the payment requests in their one currency. An invoice whose
     * amounts disagree on currency has no meaningful total, so all three are
     * null rather than a sum across currencies.
     *
     * @param  list<array<string, mixed>>  $requests
     * @param  array{amount: int, currency: string}|null  $next
     * @return array{currency: string|null, total_amount: int|null, paid_amount: int|null, next_payment_amount: int|null}
     */
    private static function money(array $requests, ?array $next): array
    {
        $currencies = [];
        $total = null;
        $paid = null;
        foreach ($requests as $request) {
            $computed = self::amount($request['computed_amount_money'] ?? null);
            if ($computed !== null) {
                $currencies[] = $computed[1];
                $total = ($total ?? 0) + $computed[0];
            }
            $completed = self::amount($request['total_completed_amount_money'] ?? null);
            if ($completed !== null) {
                $currencies[] = $completed[1];
                $paid = ($paid ?? 0) + $completed[0];
            }
        }
        if ($next !== null) {
            $currencies[] = $next['currency'];
        }
        $currencies = array_values(array_unique($currencies));
        if (count($currencies) > 1) {
            return ['currency' => null, 'total_amount' => null, 'paid_amount' => null, 'next_payment_amount' => null];
        }

        return [
            'currency' => $currencies[0] ?? null,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'next_payment_amount' => $next['amount'] ?? null,
        ];
    }

    /**
     * A presented Square money value as [minor units, currency].
     *
     * @return array{int, string}|null
     */
    private static function amount(mixed $money): ?array
    {
        if (! is_array($money) || ! is_int($money['amount'] ?? null) || ! is_string($money['currency'] ?? null)) {
            return null;
        }

        return [$money['amount'], $money['currency']];
    }

    /**
     * When the invoice is due: its balance request's due date, or else the
     * latest due date among its requests.
     *
     * @param  list<array<string, mixed>>  $requests
     */
    private static function dueOn(array $requests): ?string
    {
        $latest = null;
        foreach ($requests as $request) {
            $due = self::date($request['due_date'] ?? null);
            if ($due === null) {
                continue;
            }
            if (($request['request_type'] ?? null) === 'BALANCE') {
                return $due;
            }
            $latest = $latest === null || $due > $latest ? $due : $latest;
        }

        return $latest;
    }

    /** @param  array<mixed>  $recipient */
    private static function name(array $recipient): ?string
    {
        $company = is_string($recipient['company_name'] ?? null) ? trim($recipient['company_name']) : '';
        if ($company !== '') {
            return $company;
        }
        $person = trim(implode(' ', array_filter([
            is_string($recipient['given_name'] ?? null) ? trim($recipient['given_name']) : '',
            is_string($recipient['family_name'] ?? null) ? trim($recipient['family_name']) : '',
        ])));

        return $person === '' ? null : mb_substr($person, 0, 255);
    }

    private static function id(mixed $id): ?string
    {
        return is_string($id) && preg_match(SquareClient::ID_PATTERN, $id) === 1 ? $id : null;
    }

    private static function date(mixed $date): ?string
    {
        if (! is_string($date) || preg_match(self::DATE, $date, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return $date;
    }

    /** An RFC 3339 instant, as a UTC database timestamp. */
    private static function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  array<string, mixed>  $presented */
    private static function fingerprint(array $presented): string
    {
        return hash('sha256', json_encode($presented, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
