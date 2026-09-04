<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A JSON column of email addresses, read as a list whatever the column holds.
 *
 * `array` was the obvious cast and it is not enough. A JSON column is storage,
 * not a type: `json_decode` returns whatever is in the row, and a row can hold
 * a bare string, an object, a number or a null. Every one of those reaches the
 * browser as something that is not a JavaScript array, and the invoice screen
 * calls `.join` on it - so one malformed row from an import, a restore or a
 * hand-edit blanks the whole page, including the deliveries either side of it
 * that were fine.
 *
 * So the invariant is enforced here rather than at each of the four places that
 * read these columns - this screen, the send response, the MCP payload and the
 * mailer. A reader gets `list<string>` or it gets `[]`; there is no third
 * answer and no null.
 *
 * Nothing is inferred from a malformed value beyond making it a list. A string
 * becomes one element rather than being split on commas: a legacy row reading
 * "ap@acme.test, ar@acme.test" renders identically either way, and guessing a
 * separator would invent a structure the row never claimed to have.
 *
 * @implements CastsAttributes<list<string>, iterable<mixed>|string|null>
 */
final class EmailAddressList implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return self::listFrom(is_string($value) ? json_decode($value, true) : $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        // Normalised on the way in as well as out, so a writer that has not
        // been through `InvoiceEmailDraft` cannot leave a row that only the
        // reader above is holding together.
        return [$key => json_encode(self::listFrom($value), JSON_THROW_ON_ERROR)];
    }

    /**
     * Whatever was there, as a list of non-empty trimmed strings.
     *
     * Keyed while collecting and renumbered at the end, which does the
     * de-duplication and the renumbering in one step. Both matter: `array_filter`
     * on an address list leaves gaps, and a PHP array with gaps is a JSON
     * *object* - the shape the browser cannot call `.join` on.
     *
     * @return list<string>
     */
    private static function listFrom(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_iterable($value)) {
            return [];
        }

        $addresses = [];

        foreach ($value as $address) {
            // Skipped rather than stringified. A nested value cannot be
            // rendered as an address, and casting one would put
            // "[object Object]" on the record of what a client was sent.
            if (! is_string($address)) {
                continue;
            }

            $address = trim($address);

            if ($address !== '') {
                $addresses[$address] = $address;
            }
        }

        return array_values($addresses);
    }
}
