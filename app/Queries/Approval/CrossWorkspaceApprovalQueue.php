<?php

namespace App\Queries\Approval;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ReviewerGrant;
use App\Models\User;
use App\Services\Review\ReviewerGrants;

/**
 * One "To approve" across every provider that lets this person decide
 * (docs/ux-overhaul.md sections 7c and 7d).
 *
 * It is the fan-out {@see PortalApprovalQueue} was written for. A provider
 * reaches the person in one of two ways, and only two:
 *
 * - **a portal membership** of one of its clients, with the approval
 *   permission (the billing contact by default, or an explicit grant), exactly
 *   as the portal home decides it; or
 * - **a reviewer grant** the provider gave them, which reaches one of its
 *   clients and nothing else.
 *
 * Nothing else crosses a workspace boundary. No workspace is read because the
 * person belongs to it, only because one of those two says so, and every block
 * is the single-workspace queue for one client, so each is its own bounded set
 * of queries however many entries it holds. Blocks are bounded in number too.
 *
 * Someone who holds a membership of a client is decided by it alone: a grant
 * for the same client is not consulted (see ReviewerGrants::reaching), so the
 * same rows never appear twice and a grant never widens a membership.
 *
 * A block through a grant carries no invoice link, rate or internal note; see
 * {@see PortalApprovalQueue::forGrant()}.
 */
final class CrossWorkspaceApprovalQueue
{
    /** The most portal memberships read for one person. */
    public const int MEMBERSHIP_LIMIT = 50;

    public function __construct(
        private readonly PortalApprovalQueue $queue,
        private readonly ReviewerGrants $grants,
    ) {}

    /**
     * @return array{blocks: list<array<string, mixed>>, reads: list<array{grant: ReviewerGrant, rows: int}>}
     *                                                                                                        `reads` is what was disclosed through a grant, for the granting workspace's audit
     */
    public function for(User $viewer): array
    {
        $blocks = [];
        $reads = [];

        $memberships = ClientCompanyMembership::query()
            ->where('user_id', $viewer->id)
            ->with('clientCompany.workspace')
            ->orderBy('id')
            ->limit(self::MEMBERSHIP_LIMIT)
            ->get();
        foreach ($memberships as $membership) {
            $company = $membership->clientCompany;
            if (! $company instanceof ClientCompany || (int) $company->workspace_id !== (int) $membership->workspace_id) {
                continue;
            }
            $queue = $this->queue->forCompany(
                $company,
                $viewer,
                route('approvals.approve', ['company', $company->public_id], absolute: false),
                route('approvals.dispute', ['company', $company->public_id], absolute: false),
            );
            if ($queue !== null) {
                $blocks[] = $this->block($company->public_id, 'portal', (string) $company->workspace->name, $company->name, $queue);
            }
        }

        foreach ($this->grants->reaching($viewer) as $grant) {
            $queue = $this->queue->forGrant(
                $grant,
                route('approvals.approve', ['grant', $grant->public_id], absolute: false),
                route('approvals.dispute', ['grant', $grant->public_id], absolute: false),
            );
            $blocks[] = $this->block($grant->public_id, 'grant', (string) $grant->granterWorkspace->name, (string) $grant->company->name, $queue);

            $rows = $this->rowCount($queue);
            if ($rows > 0) {
                $reads[] = ['grant' => $grant, 'rows' => $rows];
            }
        }

        usort($blocks, static fn (array $a, array $b): int => [$a['provider'], $a['client'], $a['key']] <=> [$b['provider'], $b['client'], $b['key']]);

        return ['blocks' => $blocks, 'reads' => $reads];
    }

    /**
     * @param  array<string, mixed>  $queue
     * @return array<string, mixed>
     */
    private function block(string $key, string $source, string $provider, string $client, array $queue): array
    {
        return [
            'key' => $key,
            'source' => $source,
            'provider' => $provider,
            'client' => $client,
            'pending' => $this->pendingCount($queue),
            'to_approve' => $queue,
        ];
    }

    /** @param array<string, mixed> $queue */
    private function pendingCount(array $queue): int
    {
        $count = 0;
        foreach ($queue['groups'] as $group) {
            $count += $group['hours']['pending_count'] + $group['expenses']['pending_count'];
        }

        return $count;
    }

    /** @param array<string, mixed> $queue */
    private function rowCount(array $queue): int
    {
        $count = 0;
        foreach ($queue['groups'] as $group) {
            $count += count($group['hours']['entries']) + count($group['expenses']['items']);
        }

        return $count;
    }
}
