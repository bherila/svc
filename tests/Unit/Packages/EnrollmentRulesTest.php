<?php

namespace Tests\Unit\Packages;

use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\User;
use App\Support\Packages\EnrollmentSigner;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\EnrollmentVersions;
use App\Support\Packages\PackageActivationMode;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\PackageTerms;
use App\Support\Packages\PortalAddRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Requirement;

/**
 * The enrollment rules that need no database: the state machine, where an
 * acceptance goes, whether a package may be added from the portal, which
 * version an enrollment stands on, who counts as the same signer, and what
 * changed between two versions.
 */
final class EnrollmentRulesTest extends TestCase
{
    /** Every legal move; every other pair is illegal. */
    private const array LEGAL = [
        'offered' => ['awaiting_payment', 'awaiting_review', 'active', 'withdrawn'],
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

    public function test_only_an_offer_or_a_withdrawn_one_counts_as_not_accepted(): void
    {
        $accepted = array_map(static fn (EnrollmentStatus $status): string => $status->value, array_values(array_filter(EnrollmentStatus::cases(), static fn (EnrollmentStatus $status): bool => $status->isAccepted())));

        $this->assertSame(['awaiting_payment', 'awaiting_review', 'active', 'lapsed'], $accepted);
    }

    #[Requirement('ACC-5')]
    public function test_an_accepted_enrollment_owing_nothing_starts_or_waits_by_its_activation_mode(): void
    {
        $this->assertSame(EnrollmentStatus::Active, PackageActivationMode::Automatic->statusOnceAccepted());
        $this->assertSame(EnrollmentStatus::AwaitingReview, PackageActivationMode::Review->statusOnceAccepted());
    }

    /** @return iterable<string, array{string, bool, bool, array<string, mixed>, PackageRefusal|null}> */
    public static function additions(): iterable
    {
        yield 'published, addable, free' => ['published', true, true, [], null];
        yield 'not portal-addable' => ['published', false, true, [], PackageRefusal::NotAddable];
        yield 'retired' => ['retired', true, true, [], PackageRefusal::NotAddable];
        yield 'draft' => ['draft', true, false, [], PackageRefusal::NotAddable];
        yield 'published with no version' => ['published', true, false, [], PackageRefusal::NotAddable];
        yield 'charges the first period' => ['published', true, true, ['charge_first_period' => true], PackageRefusal::PaymentNotAvailableYet];
        yield 'charges the setup fee at sign-up' => ['published', true, true, ['charge_setup_fee_at_signup' => true, 'components' => [['kind' => 'setup_fee', 'description' => 'Setup', 'amount' => 100]]], PackageRefusal::PaymentNotAvailableYet];
        yield 'a setup fee billed later is free at sign-up' => ['published', true, true, ['components' => [['kind' => 'setup_fee', 'description' => 'Setup', 'amount' => 100]]], null];
        yield 'requires autopay' => ['published', true, true, ['require_autopay' => true, 'autopay_limit_amount' => 100], PackageRefusal::PaymentNotAvailableYet];
        yield 'starts only once paid' => ['published', true, true, ['pay_before_activation' => true], PackageRefusal::PaymentNotAvailableYet];
    }

    /** @param array<string, mixed> $terms */
    #[DataProvider('additions')]
    #[Requirement('ACC-8', 'PKG-6')]
    public function test_whether_a_package_may_be_added_from_the_portal(string $status, bool $addable, bool $hasVersion, array $terms, ?PackageRefusal $expected): void
    {
        $package = $this->package($status, $addable ? 1 : null);
        $package->portal_addable = $addable;

        $this->assertSame($expected, PortalAddRule::refusal($package, $hasVersion ? $this->terms($terms) : null));
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
