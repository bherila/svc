<?php

namespace Tests\Unit\Submission;

use App\Support\Billing\SubcontractorBillingMode;
use App\Support\Submission\SubmissionRules;
use App\Support\Submission\SubmitRefusal;
use App\Support\Submission\WithdrawRefusal;
use PHPUnit\Framework\TestCase;

final class SubmissionRulesTest extends TestCase
{
    private function time(?int $author = 1, int $actor = 1, string $status = 'draft', bool $submitted = false, bool $billable = true, bool $shown = true, ?SubcontractorBillingMode $mode = null): ?SubmitRefusal
    {
        return SubmissionRules::timeRefusal($author, $actor, $status, $submitted, $billable, $shown, $mode);
    }

    public function test_a_billable_shown_draft_of_your_own_may_be_submitted(): void
    {
        $this->assertNull($this->time());
        $this->assertNull($this->time(mode: SubcontractorBillingMode::FlatHourly));
        $this->assertNull($this->time(mode: SubcontractorBillingMode::Retainer));
    }

    public function test_each_refusal_is_exact(): void
    {
        $this->assertSame(SubmitRefusal::NotYours, $this->time(author: 2));
        $this->assertSame(SubmitRefusal::NotYours, $this->time(author: null));
        $this->assertSame(SubmitRefusal::AlreadySubmitted, $this->time(submitted: true));
        $this->assertSame(SubmitRefusal::NotDraft, $this->time(status: 'approved'));
        $this->assertSame(SubmitRefusal::NotDraft, $this->time(status: 'invoiced'));
        $this->assertSame(SubmitRefusal::NotDraft, $this->time(status: ''));
        $this->assertSame(SubmitRefusal::NotBillable, $this->time(billable: false));
        $this->assertSame(SubmitRefusal::NotShownToClient, $this->time(shown: false));
        $this->assertSame(SubmitRefusal::DirectTime, $this->time(mode: SubcontractorBillingMode::Direct));
    }

    public function test_the_refusals_are_checked_in_a_fixed_order(): void
    {
        $this->assertSame(SubmitRefusal::NotYours, $this->time(author: 2, status: 'approved', submitted: true, billable: false, shown: false, mode: SubcontractorBillingMode::Direct));
        $this->assertSame(SubmitRefusal::AlreadySubmitted, $this->time(status: 'approved', submitted: true, billable: false, shown: false, mode: SubcontractorBillingMode::Direct));
        $this->assertSame(SubmitRefusal::NotDraft, $this->time(status: 'approved', billable: false, shown: false, mode: SubcontractorBillingMode::Direct));
        $this->assertSame(SubmitRefusal::NotBillable, $this->time(billable: false, shown: false, mode: SubcontractorBillingMode::Direct));
        $this->assertSame(SubmitRefusal::NotShownToClient, $this->time(shown: false, mode: SubcontractorBillingMode::Direct));
    }

    public function test_expenses_follow_the_author_submitted_and_draft_rules_only(): void
    {
        $this->assertNull(SubmissionRules::expenseRefusal(1, 1, 'draft', false));
        $this->assertSame(SubmitRefusal::NotYours, SubmissionRules::expenseRefusal(2, 1, 'draft', false));
        $this->assertSame(SubmitRefusal::NotYours, SubmissionRules::expenseRefusal(null, 1, 'draft', false));
        $this->assertSame(SubmitRefusal::AlreadySubmitted, SubmissionRules::expenseRefusal(1, 1, 'draft', true));
        $this->assertSame(SubmitRefusal::NotDraft, SubmissionRules::expenseRefusal(1, 1, 'approved', false));
        $this->assertSame(SubmitRefusal::NotYours, SubmissionRules::expenseRefusal(2, 1, 'approved', true));
        $this->assertSame(SubmitRefusal::AlreadySubmitted, SubmissionRules::expenseRefusal(1, 1, 'approved', true));
    }

    public function test_withdrawing_needs_your_own_unapproved_submitted_draft(): void
    {
        $this->assertNull(SubmissionRules::withdrawRefusal(1, 1, 'draft', true, false));
        $this->assertSame(WithdrawRefusal::NotYours, SubmissionRules::withdrawRefusal(2, 1, 'draft', true, false));
        $this->assertSame(WithdrawRefusal::NotYours, SubmissionRules::withdrawRefusal(null, 1, 'draft', true, false));
        $this->assertSame(WithdrawRefusal::NotSubmitted, SubmissionRules::withdrawRefusal(1, 1, 'draft', false, false));
        $this->assertSame(WithdrawRefusal::Approved, SubmissionRules::withdrawRefusal(1, 1, 'draft', true, true));
        $this->assertSame(WithdrawRefusal::ReadyToBill, SubmissionRules::withdrawRefusal(1, 1, 'approved', true, false));
        $this->assertSame(WithdrawRefusal::Approved, SubmissionRules::withdrawRefusal(1, 1, 'approved', true, true));
        $this->assertSame(WithdrawRefusal::NotYours, SubmissionRules::withdrawRefusal(2, 1, 'approved', false, true));
        $this->assertSame(WithdrawRefusal::NotSubmitted, SubmissionRules::withdrawRefusal(1, 1, 'approved', false, true));
    }

    public function test_what_is_on_offer(): void
    {
        $this->assertSame(['approved', 'invoiced'], SubmissionRules::readyStatuses());
        $this->assertTrue(SubmissionRules::onOffer('approved', false));
        $this->assertTrue(SubmissionRules::onOffer('invoiced', false));
        $this->assertTrue(SubmissionRules::onOffer('draft', true));
        $this->assertFalse(SubmissionRules::onOffer('draft', false));
        $this->assertFalse(SubmissionRules::onOffer('void', true));
        $this->assertFalse(SubmissionRules::onOffer('', true));
    }

    public function test_the_providers_staff_decide_only_what_is_not_a_bare_submission(): void
    {
        $this->assertFalse(SubmissionRules::mayDecide('draft', true));
        $this->assertTrue(SubmissionRules::mayDecide('draft', false));
        $this->assertTrue(SubmissionRules::mayDecide('approved', true));
        $this->assertTrue(SubmissionRules::mayDecide('approved', false));
    }

    public function test_every_refusal_has_words(): void
    {
        foreach (SubmitRefusal::cases() as $case) {
            $this->assertNotSame('', $case->message());
        }
        foreach (WithdrawRefusal::cases() as $case) {
            $this->assertNotSame('', $case->message());
        }
        $this->assertSame('Only billable time is submitted for review.', SubmitRefusal::NotBillable->message());
        $this->assertSame('This is not submitted.', WithdrawRefusal::NotSubmitted->message());
    }
}
