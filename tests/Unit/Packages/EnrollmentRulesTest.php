<?php

namespace Tests\Unit\Packages;

use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\User;
use App\Support\Billing\InvoiceStatus;
use App\Support\Packages\EnrollmentLapse;
use App\Support\Packages\EnrollmentSigner;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\EnrollmentVersions;
use App\Support\Packages\PackageActivationMode;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\PackageTerms;
use App\Support\Packages\PortalAddRule;
use App\Support\Packages\SignupChargeLine;
use App\Support\Packages\SignupCharges;
use App\Support\Packages\SignupGate;
use App\Support\Packages\SignupPaymentState;
use App\Support\Packages\SignupWait;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Requirement;

/**
 * The enrollment rules that need no database: the state machine, where an
 * acceptance goes and what it waits for, whether a package may be added from
 * the portal, which version an enrollment stands on, who counts as the same
 * signer, what changed between two versions, when an open enrollment lapses,
 * and what sign-up charges.
 */
final class EnrollmentRulesTest extends TestCase
{
    /** Every legal move; every other pair is illegal. */
    private const array LEGAL = [
        'offered' => ['awaiting_payment', 'awaiting_review', 'active', 'lapsed', 'withdrawn'],
        'awaiting_payment' => ['awaiting_review', 'active', 'lapsed'],
        'awaiting_review' => ['active'],
        'active' => [],
        'lapsed' => [],
        'withdrawn' => [],
    ];

    /** @return iterable<string, array{EnrollmentStatus, EnrollmentStatus, bool}> */
    public static function pairs(): iterable
    {
        foreach (EnrollmentStatus::cases() as $from) {
            foreach (EnrollmentStatus::cases() as $to) {
                yield "{$from->value} to {$to->value}" => [$from, $to, in_array($to->value, self::LEGAL[$from->value], true)];
            }
        }
    }

    #[DataProvider('pairs')]
    public function test_every_move_between_states_is_legal_or_illegal_as_decided(EnrollmentStatus $from, EnrollmentStatus $to, bool $legal): void
    {
        $this->assertSame($legal, $from->canMoveTo($to));
        $this->assertSame($legal, in_array($to, $from->next(), true));
    }

    public function test_an_acceptance_stands_until_the_enrollment_is_closed(): void
    {
        $accepted = array_map(static fn (EnrollmentStatus $status): string => $status->value, array_values(array_filter(EnrollmentStatus::cases(), static fn (EnrollmentStatus $status): bool => $status->isAccepted())));

        $this->assertSame(['awaiting_payment', 'awaiting_review', 'active'], $accepted);
    }

    #[Requirement('ACC-6')]
    public function test_only_an_open_offer_or_an_unpaid_acceptance_may_lapse(): void
    {
        $open = array_map(static fn (EnrollmentStatus $status): string => $status->value, array_values(array_filter(EnrollmentStatus::cases(), static fn (EnrollmentStatus $status): bool => $status->mayLapse())));

        $this->assertSame(['offered', 'awaiting_payment'], $open);
        foreach (EnrollmentStatus::cases() as $status) {
            $this->assertSame($status->mayLapse(), $status->canMoveTo(EnrollmentStatus::Lapsed), $status->value);
        }
    }

    #[Requirement('ACC-5')]
    public function test_an_accepted_enrollment_owing_nothing_starts_or_waits_by_its_activation_mode(): void
    {
        $this->assertSame(EnrollmentStatus::Active, PackageActivationMode::Automatic->statusOnceAccepted());
        $this->assertSame(EnrollmentStatus::AwaitingReview, PackageActivationMode::Review->statusOnceAccepted());
    }

    /** @return iterable<string, array{string, bool, bool, array<string, mixed>, bool, PackageRefusal|null}> */
    public static function additions(): iterable
    {
        $setupFee = ['charge_setup_fee_at_signup' => true, 'components' => [['kind' => 'setup_fee', 'description' => 'Setup', 'amount' => 100]]];
        yield 'published, addable, free' => ['published', true, true, [], false, null];
        yield 'not portal-addable' => ['published', false, true, [], true, PackageRefusal::NotAddable];
        yield 'retired' => ['retired', true, true, [], true, PackageRefusal::NotAddable];
        yield 'draft' => ['draft', true, false, [], true, PackageRefusal::NotAddable];
        yield 'published with no version' => ['published', true, false, [], true, PackageRefusal::NotAddable];
        yield 'charges the first period, with Stripe' => ['published', true, true, ['charge_first_period' => true], true, null];
        yield 'charges the first period, Stripe gone' => ['published', true, true, ['charge_first_period' => true], false, PackageRefusal::PaymentUnavailable];
        yield 'charges the setup fee at sign-up, with Stripe' => ['published', true, true, $setupFee, true, null];
        yield 'charges the setup fee at sign-up, Stripe gone' => ['published', true, true, $setupFee, false, PackageRefusal::PaymentUnavailable];
        yield 'a setup fee billed later is free at sign-up' => ['published', true, true, ['components' => [['kind' => 'setup_fee', 'description' => 'Setup', 'amount' => 100]]], false, null];
        yield 'requires autopay, Stripe gone' => ['published', true, true, ['require_autopay' => true, 'autopay_limit_amount' => 100], false, PackageRefusal::PaymentUnavailable];
        yield 'starts only once paid, with Stripe' => ['published', true, true, ['pay_before_activation' => true, 'charge_first_period' => true], true, null];
    }

    /** @param array<string, mixed> $terms */
    #[DataProvider('additions')]
    #[Requirement('ACC-8', 'PKG-6')]
    public function test_whether_a_package_may_be_added_from_the_portal(string $status, bool $addable, bool $hasVersion, array $terms, bool $stripe, ?PackageRefusal $expected): void
    {
        $package = $this->package($status, $addable ? 1 : null);
        $package->portal_addable = $addable;

        $this->assertSame($expected, PortalAddRule::refusal($package, $hasVersion ? $this->terms($terms) : null, $stripe));
    }

    /** @return iterable<string, array{array<string, mixed>, bool, bool, list<SignupWait>, EnrollmentStatus}> */
    public static function gates(): iterable
    {
        $paid = ['charge_first_period' => true, 'pay_before_activation' => true];
        $autopay = ['require_autopay' => true, 'autopay_limit_amount' => 120000];
        yield 'owes nothing, starts automatically' => [['activation_mode' => 'automatic'], false, false, [], EnrollmentStatus::Active];
        yield 'owes nothing, reviewed' => [[], false, false, [], EnrollmentStatus::AwaitingReview];
        yield 'charges but does not wait for it' => [['charge_first_period' => true, 'activation_mode' => 'automatic'], false, false, [], EnrollmentStatus::Active];
        yield 'starts once paid, unpaid' => [[...$paid, 'activation_mode' => 'automatic'], false, false, [SignupWait::Payment], EnrollmentStatus::AwaitingPayment];
        yield 'starts once paid, paid' => [[...$paid, 'activation_mode' => 'automatic'], true, false, [], EnrollmentStatus::Active];
        yield 'starts once paid, paid, reviewed' => [$paid, true, false, [], EnrollmentStatus::AwaitingReview];
        yield 'autopay required, off' => [$autopay, true, false, [SignupWait::Autopay], EnrollmentStatus::AwaitingPayment];
        yield 'autopay required, on' => [[...$autopay, 'activation_mode' => 'automatic'], true, true, [], EnrollmentStatus::Active];
        yield 'both, neither in place' => [[...$paid, ...$autopay], false, false, [SignupWait::Payment, SignupWait::Autopay], EnrollmentStatus::AwaitingPayment];
        yield 'both, only paid' => [[...$paid, ...$autopay], true, false, [SignupWait::Autopay], EnrollmentStatus::AwaitingPayment];
        yield 'both, only autopay' => [[...$paid, ...$autopay], false, true, [SignupWait::Payment], EnrollmentStatus::AwaitingPayment];
    }

    /**
     * @param  array<string, mixed>  $terms
     * @param  list<SignupWait>  $waits
     */
    #[DataProvider('gates')]
    #[Requirement('ACC-4', 'ACC-5')]
    public function test_an_accepted_sign_up_waits_only_for_what_its_version_requires(array $terms, bool $paid, bool $autopay, array $waits, EnrollmentStatus $next): void
    {
        $this->assertSame($waits, SignupGate::waitingFor($this->terms($terms), $paid, $autopay));
        $this->assertSame($next, SignupGate::next($this->terms($terms), $paid, $autopay));
    }

    /** X-3: every invoice status has a decided meaning for a sign-up, and a missing invoice is read from the terms. */
    #[Requirement('X-3', 'ACC-3')]
    public function test_the_sign_up_payment_state_is_decided_for_every_invoice_status(): void
    {
        $this->assertSame(SignupPaymentState::NotCharged, SignupPaymentState::of(false, null));
        $this->assertSame(SignupPaymentState::NotInvoiced, SignupPaymentState::of(true, null));
        $expected = ['draft' => 'not_invoiced', 'issued' => 'due', 'partially_paid' => 'due', 'paid' => 'paid', 'disputed' => 'due', 'void' => 'voided'];
        foreach (InvoiceStatus::cases() as $status) {
            $this->assertSame($expected[$status->value], SignupPaymentState::of(true, $status)->value, $status->value);
        }
    }

    /** @return iterable<string, array{string, string, string|null, int, string, string|null}> */
    public static function lapseDates(): iterable
    {
        yield 'an offer, counted from the offer' => ['offered', '2026-11-01 15:00:00', null, 14, 'UTC', '2026-11-15'];
        yield 'an acceptance, counted from the acceptance' => ['awaiting_payment', '2026-11-01 15:00:00', '2026-11-05 09:00:00', 14, 'UTC', '2026-11-19'];
        // 23:30 UTC on Nov 1 is already Nov 2 in Auckland.
        yield 'in the workspace timezone' => ['offered', '2026-11-01 23:30:00', null, 1, 'Pacific/Auckland', '2026-11-03'];
        yield 'across a month end' => ['awaiting_payment', '2026-10-20 12:00:00', '2026-10-25 12:00:00', 14, 'America/New_York', '2026-11-08'];
        yield 'a leap day' => ['offered', '2028-02-15 12:00:00', null, 14, 'UTC', '2028-02-29'];
        yield 'waiting for review, never' => ['awaiting_review', '2026-11-01 15:00:00', '2026-11-01 16:00:00', 14, 'UTC', null];
        yield 'active, never' => ['active', '2026-11-01 15:00:00', '2026-11-01 16:00:00', 14, 'UTC', null];
        yield 'lapsed, never again' => ['lapsed', '2026-11-01 15:00:00', null, 14, 'UTC', null];
        yield 'withdrawn, never' => ['withdrawn', '2026-11-01 15:00:00', null, 14, 'UTC', null];
    }

    #[DataProvider('lapseDates')]
    #[Requirement('ACC-6')]
    public function test_an_open_enrollment_lapses_on_its_lapse_date_in_the_workspace_timezone(string $status, string $offeredAt, ?string $acceptedAt, int $days, string $timezone, ?string $expected): void
    {
        $state = EnrollmentStatus::from($status);
        $offered = CarbonImmutable::parse($offeredAt, 'UTC');
        $accepted = $acceptedAt === null ? null : CarbonImmutable::parse($acceptedAt, 'UTC');

        $this->assertSame($expected, EnrollmentLapse::lapsesOn($state, $offered, $accepted, $days, $timezone)?->toDateString());
        if ($expected !== null) {
            $day = CarbonImmutable::parse($expected, $timezone);
            $this->assertFalse(EnrollmentLapse::hasLapsed($state, $offered, $accepted, $days, $timezone, $day->subDay()), 'not the day before');
            $this->assertTrue(EnrollmentLapse::hasLapsed($state, $offered, $accepted, $days, $timezone, $day), 'on the day');
            $this->assertTrue(EnrollmentLapse::hasLapsed($state, $offered, $accepted, $days, $timezone, $day->addDays(3)), 'and after');
        } else {
            $this->assertFalse(EnrollmentLapse::hasLapsed($state, $offered, $accepted, $days, $timezone, $offered->addYear()));
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string, list<array{string, int, string|null, string|null}>}> */
    public static function charges(): iterable
    {
        $hosting = ['components' => [['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 4500]]];
        $setup = ['kind' => 'setup_fee', 'description' => 'Setup and migration', 'amount' => 25000];
        yield 'nothing at sign-up' => [$hosting, '2026-11-15', []];
        yield 'setup fee only' => [['charge_setup_fee_at_signup' => true, 'components' => [...$hosting['components'], $setup]], '2026-11-15', [['setup_fee', 25000, null, null]]];
        // The engine charges a monthly item's opening incidence in full on the
        // start date; first_cycle_proration prorates the retainer only.
        yield 'first month of hosting, mid-month' => [['charge_first_period' => true, ...$hosting], '2026-11-15', [['first_period', 4500, '2026-11-15', '2026-11-30']]];
        yield 'first month and setup' => [['charge_first_period' => true, 'charge_setup_fee_at_signup' => true, 'components' => [...$hosting['components'], $setup]], '2026-11-15', [['setup_fee', 25000, null, null], ['first_period', 4500, '2026-11-15', '2026-11-30']]];
        // 16 of November's 30 days of a $600 retainer: 0.5333 x 600 = 319.98.
        yield 'a retainer, prorated by hours' => [['charge_first_period' => true, 'components' => [], 'retainer_amount' => 60000, 'retainer_minutes' => 300, 'first_cycle_proration' => 'prorate_hours'], '2026-11-15', [['first_period', 31998, '2026-11-15', '2026-11-30']]];
        yield 'a retainer, full first period' => [['charge_first_period' => true, 'components' => [], 'retainer_amount' => 60000, 'retainer_minutes' => 300, 'first_cycle_proration' => 'full_period'], '2026-11-15', [['first_period', 60000, '2026-11-15', '2026-11-30']]];
        yield 'from the first of the month' => [['charge_first_period' => true, 'components' => [['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 4500]], 'retainer_amount' => 60000, 'retainer_minutes' => 300], '2026-12-01', [['first_period', 64500, '2026-12-01', '2026-12-31']]];
        // The engine's quarterly item is anchored on the 1st of its start
        // month: opening on the 15th it bills the start and 1 February, both
        // inside the quarter anchored on the start. The estimate says so too.
        yield 'a quarter anchored on the start' => [['charge_first_period' => true, 'billing_cadence' => 'quarterly', 'components' => [['kind' => 'recurring', 'description' => 'Quarterly hosting', 'cadence' => 'quarterly', 'amount' => 12000]]], '2026-11-15', [['first_period', 24000, '2026-11-15', '2027-02-14']]];
    }

    /**
     * @param  array<string, mixed>  $terms
     * @param  list<array{string, int, string|null, string|null}>  $expected
     */
    #[DataProvider('charges')]
    #[Requirement('ACC-3', 'EDGE-6')]
    public function test_what_sign_up_charges_is_estimated_line_by_line(array $terms, string $startsOn, array $expected): void
    {
        $charges = SignupCharges::estimate($this->terms($terms), CarbonImmutable::parse($startsOn));

        $this->assertSame($expected, array_map(static fn (SignupChargeLine $line): array => [$line->kind->value, $line->amount, $line->periodStart, $line->periodEnd], $charges->lines));
        $this->assertSame(array_sum(array_column($expected, 1)), $charges->total());
    }

    #[Requirement('EDGE-7', 'EDGE-8')]
    public function test_an_enrollment_stands_on_the_version_signed_or_until_then_the_one_it_would_sign(): void
    {
        $offer = $this->enrollment('offered', offered: 1);

        $this->assertSame(2, EnrollmentVersions::toAccept($this->package('published', 2), $offer), 'a newer published version is the one signed (EDGE-7)');
        $this->assertSame(1, EnrollmentVersions::toAccept($this->package('retired', 2), $offer), 'a retired package keeps its offer (EDGE-8)');
        $this->assertNull(EnrollmentVersions::toAccept($this->package('draft', null), $offer));
        $this->assertSame(2, EnrollmentVersions::shown($this->package('published', 2), $offer));
        $this->assertSame(1, EnrollmentVersions::shown($this->package('draft', null), $offer));

        $accepted = $this->enrollment('active', offered: 1, accepted: 2);
        $this->assertSame(2, EnrollmentVersions::shown($this->package('published', 3), $accepted), 'what was signed, whatever is published since');
        $this->assertSame(1, EnrollmentVersions::shown($this->package('published', 3), $this->enrollment('withdrawn', offered: 1)), 'a closed offer shows what was offered');
    }

    #[Requirement('ACC-2')]
    public function test_the_same_signer_is_the_same_person_name_and_title(): void
    {
        $user = new User;
        $user->id = 7;
        $enrollment = $this->enrollment('active', offered: 1, accepted: 1);
        $enrollment->forceFill(['accepted_by_user_id' => 7, 'signer_name' => 'Morgan Synthetic', 'signer_title' => null]);
        $other = new User;
        $other->id = 8;

        $this->assertTrue((new EnrollmentSigner('Morgan Synthetic', null))->signed($enrollment, $user));
        $this->assertFalse((new EnrollmentSigner('Morgan Synthetic', null))->signed($enrollment, $other));
        $this->assertFalse((new EnrollmentSigner('Morgan Synthetics', null))->signed($enrollment, $user));
        $this->assertFalse((new EnrollmentSigner('Morgan Synthetic', 'Owner'))->signed($enrollment, $user));
    }

    #[Requirement('EDGE-7')]
    public function test_the_differences_between_two_versions_name_the_changed_terms_but_not_the_intake_questions(): void
    {
        $before = $this->terms();
        $after = $this->terms([
            'notice_days' => 60,
            'agreement_text' => 'Second edition.',
            'components' => [['kind' => 'recurring', 'description' => 'Hosting', 'cadence' => 'monthly', 'amount' => 5500]],
            'intake_questions' => [['key' => 'site', 'label' => 'Site', 'kind' => 'short_text', 'required' => true]],
        ]);

        $this->assertSame([], $before->differences($this->terms()));
        $this->assertSame(['agreement_text', 'components', 'notice_days'], $after->differences($before));
        $this->assertSame($after->differences($before), $before->differences($after));
    }

    /** @param array<string, mixed> $overrides */
    private function terms(array $overrides = []): PackageTerms
    {
        return PackageTerms::fromArray([
            ...PackageTerms::defaults('USD'),
            'name' => 'Web hosting',
            'agreement_text' => 'First edition.',
            'components' => [['kind' => 'recurring', 'description' => 'Hosting', 'cadence' => 'monthly', 'amount' => 4500]],
            ...$overrides,
        ]);
    }

    private function package(string $status, ?int $published): ServicePackage
    {
        $package = new ServicePackage;
        $package->forceFill(['status' => $status, 'published_version_id' => $published, 'portal_addable' => true]);

        return $package;
    }

    private function enrollment(string $status, int $offered, ?int $accepted = null): ServicePackageEnrollment
    {
        $enrollment = new ServicePackageEnrollment;
        $enrollment->forceFill(['status' => $status, 'service_package_version_id' => $offered, 'accepted_version_id' => $accepted]);

        return $enrollment;
    }
}
