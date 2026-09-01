<?php

namespace Tests\Unit\ExternalImport;

use App\Services\ExternalImport\RestoreAgreementVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A number the importer had to make unique is not drift.
 *
 * `ExternalImportService::invoiceNumber()` appends `-<source key>-<16 hex>`
 * when the number it is about to write already exists in the workspace. A
 * source that legitimately repeats a number - a draft regenerated and the old
 * one soft-deleted - therefore lands with two distinct numbers, and comparing
 * the raw source value against the stored one reports drift on every such row.
 *
 * Found against real data: a declared-restore verification refused with
 * `client_invoices.invoice_number ... 37 row(s) differ`, all of them rows the
 * importer had disambiguated. Waiving that with `--accept-drift` is the exact
 * outcome the review of #180 warned about - an operator waving through a
 * difference the importer itself created - and it would blind the check to a
 * genuinely changed number at the same time.
 *
 * ## Why shape alone is not enough
 *
 * Two things have to hold, and review caught both.
 *
 * The hash must be the one derived from *this* row's source key. Otherwise a
 * source number that legitimately ends in `-legacy-0123456789abcdef`, later
 * shortened at the source, would have its real tail stripped and the drift
 * hidden.
 *
 * And the base must allow for truncation: `invoiceNumber()` cuts the base so
 * the 26-character suffix fits inside the 80-character column, so a long
 * colliding number is stored as a prefix plus the suffix and can never equal
 * the full source value.
 */
final class RestoreDisambiguatedNumberTest extends TestCase
{
    /** The source key the importer hashes for these rows. */
    private const SOURCE_KEY = '51';

    /**
     * @return list<array{string, string, bool, string}>
     */
    public static function comparisons(): array
    {
        $hash = substr(hash('sha256', '51'), 0, 16);
        $other = substr(hash('sha256', '99'), 0, 16);
        $longBase = 'INV-'.str_repeat('X', 70);
        $suffix = '-external-'.$hash;

        return [
            ['INV-202601-001', 'INV-202601-001', true, 'An untouched number matches outright.'],
            // The two suffix tags this ledger has actually seen: rows imported
            // under the `legacy` source key, and what the current code writes.
            // Matching the tag by shape means a ledger written under one key
            // still verifies after a rename - the point of a declared restore.
            ['INV-202601-001', 'INV-202601-001-legacy-'.$hash, true, 'Disambiguated under the legacy key.'],
            ['INV-202601-001', 'INV-202601-001-external-'.$hash, true, 'And under the current key.'],
            // Truncation. The base is cut to fit the suffix inside the column,
            // so the stored value is a prefix plus the suffix.
            [$longBase, mb_substr($longBase, 0, 80 - mb_strlen($suffix)).$suffix, true, 'A truncated collision is still a disambiguation.'],
            // The guard has to keep working.
            ['INV-202601-001', 'INV-202601-999', false, 'A changed number is still drift.'],
            ['INV-202601-001', 'INV-202601-999-external-'.$hash, false, 'A changed number with a valid suffix is still drift.'],
            // The hash has to be this row's. Without that check, a source value
            // whose own tail happens to look like a suffix could be shortened
            // at the source and the difference would disappear.
            ['INV-202601-001', 'INV-202601-001-external-'.$other, false, "Another row's hash is not this row's suffix."],
            ['INV-202601-001', 'INV-202601-001-external-nothexadecimal', false, 'Only a hexadecimal hash is a suffix.'],
            ['INV-202601-001', 'INV-202601-001-external-'.substr($hash, 0, 14), false, 'A short hash is not the suffix.'],
        ];
    }

    #[DataProvider('comparisons')]
    public function test_it_accepts_a_disambiguated_number_and_nothing_else(
        string $source,
        string $stored,
        bool $expected,
        string $because,
    ): void {
        $this->assertSame(
            $expected,
            $this->same($source, $stored, 'client_invoices.invoice_number', self::SOURCE_KEY),
            $because,
        );
    }

    /**
     * The allowance is scoped to the one column that earns it.
     *
     * Any other column comparing a suffix-shaped value is drift, because no
     * other column is one the importer may rewrite on collision.
     */
    public function test_no_other_column_may_shed_a_suffix(): void
    {
        $stored = 'a-value-external-'.substr(hash('sha256', self::SOURCE_KEY), 0, 16);

        $this->assertFalse($this->same('a-value', $stored, 'client_invoices.notes', self::SOURCE_KEY));
        $this->assertFalse($this->same('a-value', $stored, null, self::SOURCE_KEY));
    }

    /** Without a source key there is nothing to validate the hash against. */
    public function test_it_refuses_to_guess_without_a_source_key(): void
    {
        $stored = 'INV-202601-001-external-'.substr(hash('sha256', self::SOURCE_KEY), 0, 16);

        $this->assertFalse($this->same('INV-202601-001', $stored, 'client_invoices.invoice_number', null));
    }

    private function same(mixed $expected, mixed $stored, ?string $qualified, ?string $sourceKey): bool
    {
        $method = new ReflectionMethod(RestoreAgreementVerifier::class, 'same');

        return (bool) $method->invoke(null, $expected, $stored, $qualified, $sourceKey);
    }
}
