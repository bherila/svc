<?php

declare(strict_types=1);

namespace App\Support\Billing\Autopay;

/**
 * The exact words autopay consent is given in and the notices that follow it.
 * The wording a client agrees to is stored verbatim on the consent record, so
 * it is composed here once and never rebuilt from a template later.
 */
final class AutopayWording
{
    /** The longest attestation a provider may enter, and the shortest worth recording. */
    public const int ATTESTATION_MAX = 1000;

    public const int ATTESTATION_MIN = 10;

    public static function authorization(string $provider, string $company, string $method, int $limit, string $currency): string
    {
        return 'I authorize '.$provider.' to charge '.$method.' for '.$company.' invoices on their due dates, up to '
            .self::money($limit, $currency).' each, until I turn autopay off.';
    }

    public static function money(int $minor, string $currency): string
    {
        $formatted = number_format($minor / 100, 2);

        return strtoupper($currency) === 'USD' ? '$'.$formatted : strtoupper($currency).' '.$formatted;
    }

    /** The line printed on the invoice and in the email that issues it. */
    public static function invoiceNotice(string $method, string $dueOn): string
    {
        return 'Will be charged to '.$method.' on '.$dueOn.'.';
    }

    /** "Visa ending 4242", or the type when nothing more is known. */
    public static function methodLabel(?string $brand, ?string $type, ?string $last4): string
    {
        $name = trim((string) $brand) !== '' ? self::title((string) $brand) : match ((string) $type) {
            'us_bank_account' => 'Bank account',
            default => self::title(str_replace('_', ' ', (string) $type)),
        };
        if ($name === '') {
            $name = 'Saved payment method';
        }

        return trim((string) $last4) !== '' ? $name.' ending '.$last4 : $name;
    }

    private static function title(string $value): string
    {
        $value = trim($value);

        return $value === '' ? '' : mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }
}
