<?php

namespace App\Support\Packages;

/**
 * A client's subscription to a package, from offer to service.
 *
 * Approval, or a company contact adding a package from the portal, creates an
 * enrollment `offered`. Acceptance moves it to `awaiting_review` or `active`
 * by the version's activation mode, or to `awaiting_payment` when sign-up
 * takes a payment (slice 3b). An owner or admin activates one awaiting review.
 * `lapsed` (unpaid past its lapse date) and `withdrawn` end it without service.
 *
 * Every move is one of {@see self::next()}; the matches are exhaustive with no
 * default arm (X-3), so a new state fails static analysis until every move out
 * of it is decided.
 */
enum EnrollmentStatus: string
{
    case Offered = 'offered';
    case AwaitingPayment = 'awaiting_payment';
    case AwaitingReview = 'awaiting_review';
    case Active = 'active';
    case Lapsed = 'lapsed';
    case Withdrawn = 'withdrawn';

    /**
     * Where an enrollment in this state may go next.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Offered => [self::AwaitingPayment, self::AwaitingReview, self::Active, self::Withdrawn],
            self::AwaitingPayment => [self::AwaitingReview, self::Active, self::Lapsed],
            self::AwaitingReview => [self::Active],
            self::Active, self::Lapsed, self::Withdrawn => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** Whether a company contact has accepted it (a lapsed one was accepted, then not paid). */
    public function isAccepted(): bool
    {
        return match ($this) {
            self::Offered, self::Withdrawn => false,
            self::AwaitingPayment, self::AwaitingReview, self::Active, self::Lapsed => true,
        };
    }
}
