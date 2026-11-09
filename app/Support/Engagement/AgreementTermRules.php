<?php

namespace App\Support\Engagement;

use App\Support\Billing\ChargeCadence;
use App\Support\Billing\FirstCycleProration;
use Illuminate\Validation\Rule;

/**
 * What a valid agreement term looks like, wherever a term is written.
 *
 * Agreements and service-package versions carry the same terms under the same
 * names, because accepting a package copies its version's terms onto an
 * agreement (PKG-7). One list of constraints keeps the two from drifting: a
 * value a package could store but an agreement would refuse would only surface
 * when a client accepted it.
 *
 * Constraints only. Whether a term is required, optional or nullable is the
 * caller's to say, so each request keeps its own presence rules and prepends
 * them through {@see Rule()}.
 */
final class AgreementTermRules
{
    /** @return list<string> */
    public static function cadences(): array
    {
        return array_map(static fn (ChargeCadence $cadence): string => $cadence->value, ChargeCadence::cases());
    }

    /** @return array<string, list<mixed>> */
    public static function constraints(): array
    {
        return [
            'agreement_text' => ['string', 'max:30000'],
            'billing_cadence' => [Rule::in(self::cadences())],
            'currency' => ['string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'hourly_rate_amount' => ['integer', 'min:0'],
            'retainer_amount' => ['integer', 'min:0'],
            'retainer_minutes' => ['integer', 'min:0'],
            'rollover_months' => ['integer', 'min:0', 'max:120'],
            'first_cycle_proration' => [Rule::in(array_map(static fn (FirstCycleProration $case): string => $case->value, FirstCycleProration::cases()))],
        ];
    }

    /**
     * One term's rule list: the caller's presence rules, then the constraints.
     *
     * @return list<mixed>
     */
    public static function rule(string $term, string ...$presence): array
    {
        return array_values(array_merge($presence, self::constraints()[$term]));
    }
}
