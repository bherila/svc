<?php

namespace App\Console\Commands\Engagement;

use App\Services\Engagement\UnlinkedProposalAgreementAuditor;
use App\Support\Engagement\UnlinkedProposalAgreementCounts;
use Illuminate\Console\Command;

/**
 * Report proposals whose agreement the duplicate guard in acceptance cannot see.
 *
 * A printer, deliberately. Every question this answers lives in
 * {@see UnlinkedProposalAgreementAuditor}, because the counting is the durable
 * part and an operator screen should show the same numbers rather than
 * re-deriving them from a second copy of the funnel that can drift from this
 * one. What each stage removes and why is documented on that service.
 *
 * It prints counts only - never a row, an id, a proposal title, a company, or a
 * workspace. That is enforced by the shape of
 * {@see UnlinkedProposalAgreementCounts} rather than by care taken here, so the
 * output is safe to paste into a public issue against a database of real client
 * records.
 *
 * It exits clean whatever it finds. #148 is explicit that acceptance must not
 * repair the link by guessing, so this is a prompt to restore known links by
 * hand rather than a deployment gate - and a non-zero exit would make it one.
 */
final class AuditUnlinkedProposalAgreementsCommand extends Command
{
    protected $signature = 'svc:engagement:audit-unlinked-proposal-agreements
        {--format=text : Output text or json}';

    protected $description = 'Count proposals whose existing agreement is invisible to the duplicate guard in acceptance';

    public function handle(UnlinkedProposalAgreementAuditor $auditor): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        // Unscoped: an operator sizing this needs every workspace at once. A
        // tenant-facing caller passes its own workspace instead.
        $counts = $auditor->count();

        if ($format === 'json') {
            $this->line((string) json_encode(
                ['summary' => $counts->toArray()],
                JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Proposals', (string) $counts->proposals);
        $this->components->twoColumnDetail('Sent, with no agreement linked back', (string) $counts->sentWithoutALinkedAgreement);
        $this->components->twoColumnDetail('... of those, whose company has an unlinked agreement', (string) $counts->withAnUnlinkedAgreementOnTheCompany);
        $this->components->twoColumnDetail('... of those, where that agreement is active', (string) $counts->withAnActiveUnlinkedAgreement);

        $this->newLine();
        $this->components->twoColumnDetail('Accepted, with no agreement linked back', (string) $counts->acceptedWithoutALinkedAgreement);
        $this->components->twoColumnDetail('Agreements naming no proposal', (string) $counts->unlinkedAgreements);

        $this->newLine();

        if ($counts->isLive()) {
            $this->components->warn(
                $counts->withAnActiveUnlinkedAgreement.' sent proposal(s) sit on a company with an active agreement that names no proposal. Acceptance refuses these proposals before writing a second agreement. Verify and restore the correct link before accepting; do not guess which agreement it was.'
            );
        } else {
            $this->components->info(
                'No sent proposal can create a duplicate contract. Every one either already links to its agreement, or its company has no unlinked active agreement to duplicate.'
            );
        }

        if ($counts->acceptedWithoutALinkedAgreement > 0) {
            $this->components->warn(
                $counts->acceptedWithoutALinkedAgreement.' accepted proposal(s) have the same broken link. They are inert today - acceptance returns early for them and creates nothing - but they are the population that becomes dangerous if that status guard is ever relaxed, or if one is moved back to sent.'
            );
        }

        return self::SUCCESS;
    }
}
