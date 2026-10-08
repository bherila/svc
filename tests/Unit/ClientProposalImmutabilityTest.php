<?php

namespace Tests\Unit;

use App\Models\ClientProposal;
use LogicException;
use ReflectionMethod;
use Tests\TestCase;

final class ClientProposalImmutabilityTest extends TestCase
{
    public function test_an_accepted_proposal_refuses_business_changes_without_persistence(): void
    {
        $proposal = new ClientProposal;
        $proposal->setRawAttributes(['status' => 'accepted', 'title' => 'Synthetic accepted terms', 'lock_version' => 1], true);
        $proposal->title = 'Changed synthetic terms';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Accepted proposals are immutable.');
        (new ReflectionMethod($proposal, 'fireModelEvent'))->invoke($proposal, 'updating');
    }

    public function test_revision_metadata_does_not_make_accepted_terms_mutable(): void
    {
        $proposal = new ClientProposal;
        $proposal->setRawAttributes(['status' => 'accepted', 'title' => 'Synthetic accepted terms', 'lock_version' => 1], true);
        $proposal->lock_version = 2;
        $proposal->updated_at = '2026-10-08 00:00:00';

        $this->assertNull((new ReflectionMethod($proposal, 'fireModelEvent'))->invoke($proposal, 'updating'));
        $this->assertSame('Synthetic accepted terms', $proposal->title);
        $this->assertSame(2, $proposal->lock_version);
    }
}
