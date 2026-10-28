<?php

namespace App\Services\Square;

use App\Support\Square\SquareDisputeFacts;

/** A bounded complete observation; partial dispute listings never authorize recording money. */
final class SquarePaymentDisputeReader
{
    public function __construct(private readonly SquareClient $client) {}

    /** @param list<string> $paymentIds
     * @return array<string,list<SquareDisputeFacts>> */
    public function read(array $paymentIds): array
    {
        $groups = [];
        $seenIds = [];
        $seenCursors = [];
        $cursor = null;
        for ($page = 0; $page < 10; $page++) {
            $response = $this->client->listDisputes($cursor);
            $rows = array_key_exists('disputes', $response) ? $response['disputes'] : [];
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100 || ! empty($response['errors'])) {
                throw SquareRequestFailed::unreadable();
            }
            foreach ($rows as $row) {
                if (! is_array($row) || ! is_string($row['id'] ?? null) || isset($seenIds[$row['id']])) {
                    throw SquareRequestFailed::unreadable();
                }
                $facts = SquareDisputeFacts::from($row, $row['id']);
                $seenIds[$facts->id] = true;
                if (in_array($facts->paymentId, $paymentIds, true)) {
                    $groups[$facts->paymentId][] = $facts;
                }
            }
            $next = $response['cursor'] ?? null;
            if ($next === null || $next === '') {
                foreach ($groups as &$group) {
                    usort($group, static fn (SquareDisputeFacts $a, SquareDisputeFacts $b): int => $a->id <=> $b->id);
                }

                return $groups;
            }
            if (! is_string($next) || preg_match(SquareClient::CURSOR_PATTERN, $next) !== 1 || isset($seenCursors[$next])) {
                throw SquareRequestFailed::unreadable();
            }
            $seenCursors[$next] = true;
            $cursor = $next;
        }

        throw SquareRequestFailed::unreadable();
    }
}
