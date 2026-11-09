<?php

namespace App\Services\Packages;

use App\Mail\ServicePackageNoticeMail;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceEmailService;
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
        $this->send(array_column($this->clientRecipients->suggestedRecipientsForCompany($company), 'email'), new ServicePackageNoticeMail(
            "{$packageName} is now active",
            "{$packageName} has started",
            ["{$workspace->name} has started {$packageName} for {$company->name}. Your signed agreement is in the client portal."],
            $agreementUrl,
            'View the agreement',
        ));
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
