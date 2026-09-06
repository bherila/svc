<?php

namespace App\Support\Billing;

use App\Services\Billing\BillingScheduleService;
use App\Services\Billing\ScheduleGenerationPreflight;
use DomainException;

/**
 * What a schedule's `line_template` has to be before it can bill anything.
 *
 * One reader, shared by the code that bills and the code that predicts. Two
 * readers is what this replaces: {@see BillingScheduleService} had its own
 * normaliser and {@see ScheduleGenerationPreflight} had a predicate that
 * "asked the same two conditions rather than throwing" - and both accepted an
 * empty array. `MoneyService::invoiceTotals([])` sums nothing to zero, so a
 * schedule whose template had been imported or hand-edited to `[]` got a clean
 * preflight, then created a draft with no lines, issued it for $0, and advanced
 * `next_run_on` past a period that had charged nobody. Every due period after
 * it went the same way, each one recorded as billed.
 *
 * The public contract already forbids that shape -
 * `StoreBillingScheduleRequest` validates `line_template` as `required`,
 * `array`, `min:1` - and nothing that reads the column should be looser than
 * the thing that writes it. Putting the rule here, once, means the preflight
 * cannot drift from the run: it reports unbillable whatever this throws on,
 * and nothing else.
 *
 * Pure and static on purpose. The preflight must be able to ask this question
 * without instantiating the service whose public entry point writes invoices;
 * a preflight that has to build the mutating service to read a column is one
 * refactor away from performing the run.
 *
 * This says little about what is *inside* a line beyond its being an object
 * with named fields. `createDraft()` prices each one through `MoneyService`
 * and refuses a missing amount or type there, and the preflight documents that
 * it rehearses what generation reads rather than what it writes. What it does
 * establish is that there is at least one line to price, which is the
 * difference between an invoice for something and an invoice for nothing.
 */
final class BillingScheduleLineTemplate
{
    /**
     * The template as a list of line definitions, or a refusal.
     *
     * @return non-empty-list<array<string, mixed>>
     *
     * @throws DomainException if the value is not a non-empty list of objects.
     *                         The message is operator-facing and says which
     *                         of the three conditions failed.
     */
    public static function normalize(mixed $value): array
    {
        if (! is_array($value)) {
            throw new DomainException('A billing schedule line template must be an array.');
        }

        if ($value === []) {
            throw new DomainException(
                'A billing schedule line template must contain at least one line; an empty one would issue an '
                .'invoice for nothing and record the period as billed.',
            );
        }

        $lines = [];
        foreach (array_values($value) as $line) {
            if (! is_array($line)) {
                throw new DomainException('A billing schedule line template entry must be an object.');
            }

            // Rebuilt field by field rather than passed through, so that what
            // comes back is an object in the JSON sense - string keys naming
            // fields - and not a list that happened to decode as an array.
            // `createLines()` reads `$line['type']` and `$line['unit_amount']`
            // by name, and a line with integer keys has neither.
            $fields = [];
            foreach ($line as $name => $field) {
                if (! is_string($name)) {
                    throw new DomainException('A billing schedule line template entry must be an object.');
                }
                $fields[$name] = $field;
            }
            $lines[] = $fields;
        }

        return $lines;
    }
}
