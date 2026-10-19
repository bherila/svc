<?php

declare(strict_types=1);

namespace App\Support\Finance;

use Illuminate\Support\Str;

/**
 * The address of one transaction in the external finance system.
 *
 * Built from configuration (`svc.finance.transaction_url`), never written into
 * code: the template is an absolute http(s) address in which
 * {@see self::PLACEHOLDER} is replaced by the transaction's UUID. The UUID is
 * the one the finance system first published and SVC stored; SVC never
 * re-points it, and resolving a UUID the finance system has since merged away
 * is that system's job.
 *
 * Null means "no link": no template, a template without the placeholder, a
 * value that is not a UUID, or a result that is not an absolute http(s)
 * address - a `javascript:` template is refused here rather than rendered as a
 * link.
 */
final readonly class FinanceTransactionLink
{
    public const string PLACEHOLDER = '{transaction_uuid}';

    public function __construct(private ?string $template) {}

    public static function configured(): self
    {
        $template = config('svc.finance.transaction_url');

        return new self(is_string($template) ? $template : null);
    }

    public function href(string $transactionUuid): ?string
    {
        $template = trim((string) $this->template);
        if ($template === '' || ! str_contains($template, self::PLACEHOLDER) || ! Str::isUuid($transactionUuid)) {
            return null;
        }

        $href = str_replace(self::PLACEHOLDER, strtolower($transactionUuid), $template);
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        $host = parse_url($href, PHP_URL_HOST);

        if (! in_array($scheme, ['https', 'http'], true) || ! is_string($host) || $host === '') {
            return null;
        }

        return $href;
    }
}
