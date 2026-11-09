<?php

namespace App\Services\Packages;

use App\Mail\ServicePackageNoticeMail;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceEmailService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Who hears about what, for service packages, and in what words.
 *
 * Every message is sent after the change commits (X-5), explicitly through
 * DB::afterCommit rather than by asking whether a transaction is open, and
 * in the request: nothing here is queued, because nothing in production reads
 * a queue. A failure to send is logged and never undoes the change.
 */
final class PackageNotifier
{
    public function __construct(private readonly InvoiceEmailService $clientRecipients) {}

    /** @return list<string> the workspace's owners' and admins' addresses */
    public function managerAddresses(Workspace $workspace): array
    {
        return array_values(array_unique(User::query()
            ->join('workspace_memberships', 'workspace_memberships.user_id', '=', 'users.id')
            ->where('workspace_memberships.workspace_id', $workspace->id)
            ->whereIn('workspace_memberships.role', ['owner', 'admin'])
            ->orderBy('users.id')
            ->pluck('users.email')
            ->filter(static fn (mixed $email): bool => is_string($email) && $email !== '')
            ->all()));
    }

    public function requestReceived(Workspace $workspace, string $packageName, string $companyName, string $reviewUrl): void
    {
        $this->send($this->managerAddresses($workspace), new ServicePackageNoticeMail(
            "New request for {$packageName}",
            "{$companyName} requested {$packageName}",
            ['A visitor asked for this package on its public page. Nothing has been created yet: review the request to approve or decline it.'],
            $reviewUrl,
            'Review the request',
        ));
    }

    public function requestDeclined(Workspace $workspace, string $email, string $packageName, ?string $reason): void
    {
        $this->send([$email], new ServicePackageNoticeMail(
            "Your request for {$packageName}",
            "{$workspace->name} can't take on this request",
            $reason === null || $reason === ''
                ? ["Thank you for your interest in {$packageName}. {$workspace->name} has decided not to go ahead with this request."]
                : ["Thank you for your interest in {$packageName}. {$workspace->name} has decided not to go ahead with this request.", "Their note: {$reason}"],
        ));
    }

    public function accessGranted(Workspace $workspace, string $email, string $companyName, string $packageName, string $portalUrl): void
    {
        $this->send([$email], new ServicePackageNoticeMail(
            "{$packageName} is ready for you to review",
            "Your request for {$packageName} was accepted",
            ["{$workspace->name} accepted your request and set up {$companyName} in their client portal. Sign in to review the agreement and finish signing up."],
            $portalUrl,
            'Open the client portal',
        ));
    }

    /**
     * The client's service has started (ACC-7). Sent to the people every
     * client notice goes to: the company's billing address and its portal
     * contacts.
     */
    public function serviceActivated(Workspace $workspace, ClientCompany $company, string $packageName, string $agreementUrl): void
    {
        $this->send($this->clientAddresses($company), new ServicePackageNoticeMail(
            "{$packageName} is now active",
            "{$packageName} has started",
            ["{$workspace->name} has started {$packageName} for {$company->name}. Your signed agreement is in the client portal."],
            $agreementUrl,
            'View the agreement',
        ));
    }

    /**
     * A company contact asked to end an agreement, in `request` mode (CAN-6).
     */
    public function cancellationRequested(Workspace $workspace, string $companyName, string $agreementTitle, CarbonInterface $endsOn, ?string $reason, string $reviewUrl): void
    {
        $paragraphs = ["{$companyName} asked to end {$agreementTitle} on ".self::day($endsOn).'. The service continues until you decide: approve it on that date or another one offered, or decline it.'];
        if ($reason !== null && $reason !== '') {
            $paragraphs[] = "Their reason: {$reason}";
        }
        $this->send($this->managerAddresses($workspace), new ServicePackageNoticeMail(
            "{$companyName} asked to cancel {$agreementTitle}",
            'Cancellation requested',
            $paragraphs,
            $reviewUrl,
            'Review the request',
        ));
    }

    /**
     * A company contact scheduled an agreement's end themselves, in
     * `self_serve` mode (CAN-6). It can be undone until the date.
     */
    public function cancellationScheduled(Workspace $workspace, string $companyName, string $agreementTitle, CarbonInterface $endsOn, ?string $reason, string $agreementUrl): void
    {
        $paragraphs = ["{$companyName} cancelled {$agreementTitle} from the client portal. It ends on ".self::day($endsOn).', and stays active until then. You or the client can undo it before that date.'];
        if ($reason !== null && $reason !== '') {
            $paragraphs[] = "Their reason: {$reason}";
        }
        $this->send($this->managerAddresses($workspace), new ServicePackageNoticeMail(
            "{$companyName} cancelled {$agreementTitle}",
            'Cancellation scheduled',
            $paragraphs,
            $agreementUrl,
            'View the agreement',
        ));
    }

    /**
     * The client's cancellation is accepted, with its end date (CAN-6): their
     * own self-serve cancellation, an approved request, or an end an owner or
     * admin scheduled. An amount still owed stays owed (EDGE-11).
     */
    public function cancellationAccepted(Workspace $workspace, ClientCompany $company, string $agreementTitle, CarbonInterface $endsOn, ?string $note, string $portalUrl): void
    {
        $paragraphs = ["{$agreementTitle} for {$company->name} ends on ".self::day($endsOn).'. The service and its billing continue until then, and anything already invoiced stays due.'];
        if ($note !== null && $note !== '') {
            $paragraphs[] = "{$workspace->name}'s note: {$note}";
        }
        $this->send($this->clientAddresses($company), new ServicePackageNoticeMail(
            "{$agreementTitle} ends on ".self::day($endsOn),
            'Your cancellation is confirmed',
            $paragraphs,
            $portalUrl,
            'View in the client portal',
        ));
    }

    /** The client's cancellation request was declined, with the note (CAN-4). */
    public function cancellationDeclined(Workspace $workspace, ClientCompany $company, string $agreementTitle, ?string $note, string $portalUrl): void
    {
        $paragraphs = ["{$workspace->name} declined the request to cancel {$agreementTitle} for {$company->name}. The service continues as before."];
        if ($note !== null && $note !== '') {
            $paragraphs[] = "Their note: {$note}";
        }
        $this->send($this->clientAddresses($company), new ServicePackageNoticeMail(
            "Your cancellation request for {$agreementTitle}",
            'Cancellation request declined',
            $paragraphs,
            $portalUrl,
            'View in the client portal',
        ));
    }

    /**
     * A scheduled end was undone (EDGE-9): owners and admins and the client
     * both hear about it, whoever undid it.
     */
    public function cancellationUndone(Workspace $workspace, ClientCompany $company, string $agreementTitle, CarbonInterface $undoneEnd, ?CarbonInterface $restoredEnd, string $agreementUrl, string $portalUrl): void
    {
        $continues = $restoredEnd === null ? 'It continues with no end date.' : 'It continues until '.self::day($restoredEnd).', its end date before the cancellation.';
        $this->send($this->managerAddresses($workspace), new ServicePackageNoticeMail(
            "{$company->name}: cancellation of {$agreementTitle} undone",
            'Cancellation undone',
            ["The end of {$agreementTitle} for {$company->name} on ".self::day($undoneEnd)." was undone. {$continues}"],
            $agreementUrl,
            'View the agreement',
        ));
        $this->send($this->clientAddresses($company), new ServicePackageNoticeMail(
            "{$agreementTitle} will continue",
            'Cancellation undone',
            ["The cancellation of {$agreementTitle} for {$company->name} on ".self::day($undoneEnd)." was undone. {$continues}"],
            $portalUrl,
            'View in the client portal',
        ));
    }

    /** @return list<string> the client's billing address and portal contacts, as for every client notice */
    private function clientAddresses(ClientCompany $company): array
    {
        return array_column($this->clientRecipients->suggestedRecipientsForCompany($company), 'email');
    }

    private static function day(CarbonInterface $date): string
    {
        return $date->format('M j, Y');
    }

    /** @param list<string> $to */
    private function send(array $to, ServicePackageNoticeMail $mail): void
    {
        if ($to === []) {
            return;
        }

        DB::afterCommit(static function () use ($to, $mail): void {
            try {
                Mail::to($to)->send($mail);
            } catch (Throwable $failure) {
                Log::warning('A service package notice could not be sent.', ['subject' => $mail->subjectLine, 'error' => $failure->getMessage()]);
            }
        });
    }
}
