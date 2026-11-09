<?php

namespace App\Support\Packages\Migration;

use App\Support\Billing\Autopay\AutopayWording;
use App\Support\Billing\ChargeCadence;
use App\Support\Packages\CancellationMode;
use App\Support\Packages\PackageActivationMode;
use App\Support\Packages\PackageComponentKind;
use App\Support\Packages\PackageComponentTerms;
use App\Support\Packages\PackageInvoiceIssuing;
use App\Support\Packages\PackageTerms;
use App\Support\Packages\PublicPackagePresenter;

/**
 * What a move changes, term by term, in words (MIG-2): the old and the new
 * value of every term that differs, finished here so the email, the portal
 * notice, the owner's preview and the agent API all say the same thing.
 *
 * A client is shown the terms of the service they receive and pay for. An
 * owner also sees the operator's settings, and the terms that only shape a
 * new sign-up (setup fee, minimum term, activation), marked as such: a move
 * never charges a setup fee again and never restarts a minimum term.
 *
 * Pure: two versions' terms in, a list out.
 */
final class MigrationTermChanges
{
    /** @return list<array{term: string, label: string, from: string, to: string}> */
    public static function forClient(PackageTerms $from, PackageTerms $to): array
    {
        return self::changes(self::clientTerms($from), self::clientTerms($to));
    }

    /** @return list<array{term: string, label: string, from: string, to: string}> */
    public static function forOwner(PackageTerms $from, PackageTerms $to): array
    {
        return self::changes([...self::clientTerms($from), ...self::ownerTerms($from)], [...self::clientTerms($to), ...self::ownerTerms($to)]);
    }

    /**
     * @param  array<string, array{string, string}>  $from  term => [label, value]
     * @param  array<string, array{string, string}>  $to
     * @return list<array{term: string, label: string, from: string, to: string}>
     */
    private static function changes(array $from, array $to): array
    {
        $changes = [];
        foreach ($to as $term => [$label, $value]) {
            $before = $from[$term][1];
            if ($before !== $value) {
                // The text itself is too long to quote here; the agreement shows it.
                $changes[] = $term === 'agreement_text'
                    ? ['term' => $term, 'label' => $label, 'from' => $before === '' ? 'None' : 'The current text', 'to' => $value === '' ? 'None' : 'Revised: read it with the new terms']
                    : ['term' => $term, 'label' => $label, 'from' => $before, 'to' => $value];
            }
        }

        return $changes;
    }

    /** @return array<string, array{string, string}> */
    private static function clientTerms(PackageTerms $terms): array
    {
        $currency = $terms->currency;

        return [
            'name' => ['Service', $terms->name],
            'billing_cadence' => ['Billing', self::cadence($terms->billingCadence)],
            'cycle_amount' => ['Price', self::money(PublicPackagePresenter::cycleAmount($terms), $currency).' '.self::per($terms->billingCadence)],
            'components' => ['Included', self::components($terms, $currency)],
            'hourly_rate_amount' => ['Hourly rate', $terms->hourlyRateAmount === null ? 'None' : self::money($terms->hourlyRateAmount, $currency).' an hour'],
            'retainer_amount' => ['Retainer', $terms->retainerAmount === null ? 'None' : self::money($terms->retainerAmount, $currency).' '.self::per($terms->billingCadence)],
            'retainer_minutes' => ['Retainer hours', $terms->retainerMinutes === null ? 'None' : self::hours($terms->retainerMinutes).' '.self::per($terms->billingCadence)],
            'rollover_months' => ['Unused hours carry over', $terms->rolloverMonths === null || $terms->rolloverMonths === 0 ? 'No' : self::count($terms->rolloverMonths, 'month')],
            'notice_days' => ['Notice to cancel', $terms->noticeDays === 0 ? 'None' : self::count($terms->noticeDays, 'day')],
            'cancellation_mode' => ['Cancelling', self::cancellation($terms->cancellationMode)],
            'agreement_text' => ['Agreement text', $terms->agreementText ?? ''],
        ];
    }

    /** @return array<string, array{string, string}> */
    private static function ownerTerms(PackageTerms $terms): array
    {
        return [
            'invoice_issuing' => ['Recurring invoices', match ($terms->invoiceIssuing) {
                PackageInvoiceIssuing::Automatic => 'Issued automatically',
                PackageInvoiceIssuing::Draft => 'Drafted for review',
            }],
            'require_autopay' => ['Autopay', $terms->requireAutopay ? 'Required, up to '.self::money((int) $terms->autopayLimitAmount, $terms->currency) : 'Not required'],
            'setup_fee' => ['Setup fee (new sign-ups only)', $terms->setupFeeTotal() === 0 ? 'None' : self::money($terms->setupFeeTotal(), $terms->currency)],
            'minimum_term_months' => ['Minimum term (new sign-ups only)', $terms->minimumTermMonths === null || $terms->minimumTermMonths === 0 ? 'None' : self::count($terms->minimumTermMonths, 'month')],
            'activation_mode' => ['Activation (new sign-ups only)', match ($terms->activationMode) {
                PackageActivationMode::Automatic => 'Starts automatically',
                PackageActivationMode::Review => 'Reviewed first',
            }],
        ];
    }

    private static function components(PackageTerms $terms, string $currency): string
    {
        $items = array_map(
            static fn (PackageComponentTerms $component): string => $component->description.': '.self::money($component->total(), $currency).' '.self::cadence($component->cadence, true),
            array_values(array_filter($terms->components, static fn (PackageComponentTerms $component): bool => $component->kind === PackageComponentKind::Recurring)),
        );

        return $items === [] ? 'Nothing recurring' : implode('; ', $items);
    }

    private static function cadence(ChargeCadence $cadence, bool $lower = false): string
    {
        $word = match ($cadence) {
            ChargeCadence::Monthly => 'Monthly',
            ChargeCadence::Quarterly => 'Quarterly',
            ChargeCadence::SemiAnnual => 'Every six months',
            ChargeCadence::Annual => 'Yearly',
            ChargeCadence::OneTime => 'Once',
        };

        return $lower ? strtolower($word) : $word;
    }

    private static function per(ChargeCadence $cadence): string
    {
        return match ($cadence) {
            ChargeCadence::Monthly => 'a month',
            ChargeCadence::Quarterly => 'a quarter',
            ChargeCadence::SemiAnnual => 'every six months',
            ChargeCadence::Annual => 'a year',
            ChargeCadence::OneTime => 'once',
        };
    }

    private static function cancellation(CancellationMode $mode): string
    {
        return match ($mode) {
            CancellationMode::None => 'By contacting us',
            CancellationMode::Request => 'On request from the client portal',
            CancellationMode::SelfServe => 'Self-serve from the client portal',
        };
    }

    private static function money(int $minor, string $currency): string
    {
        return AutopayWording::money($minor, $currency);
    }

    private static function hours(int $minutes): string
    {
        $hours = rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.');

        return $hours.' '.($hours === '1' ? 'hour' : 'hours');
    }

    private static function count(int $value, string $unit): string
    {
        return $value.' '.$unit.($value === 1 ? '' : 's');
    }
}
