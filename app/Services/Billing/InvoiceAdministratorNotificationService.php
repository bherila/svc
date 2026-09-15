<?php

namespace App\Services\Billing;

use App\Mail\AdministratorInvoiceIssuedMail;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceAdministratorNotification;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Billing\InvoiceLineDetail;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/** Durable, administrator-only delivery of the document that was first issued. */
final class InvoiceAdministratorNotificationService
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly InvoiceDocumentService $documents,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * Register the issued revision inside the issuance transaction and send
     * only after its outermost transaction commits.
     */
    public function registerIssued(ClientInvoice $invoice): ClientInvoiceAdministratorNotification
    {
        $existing = ClientInvoiceAdministratorNotification::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)
            ->where('invoice_revision', $invoice->document_revision)
            ->first();

        if ($existing instanceof ClientInvoiceAdministratorNotification) {
            return $existing;
        }

        $workspace = Workspace::query()->whereKey($invoice->workspace_id)->firstOrFail();
        $company = ClientCompany::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->whereKey($invoice->client_company_id)
            ->firstOrFail();
        $invoice->setRelation('workspace', $workspace);
        $invoice->setRelation('clientCompany', $company);

        $administrator = $this->responsibleAdministrator($invoice);
        $subject = 'Review issued invoice '.$invoice->invoice_number;
        $openUrl = route('clients.invoice', [
            $workspace,
            $company,
            $invoice,
        ]);

        $now = $this->clock->now($workspace)->utc();
        $attributes = [
            'workspace_id' => $invoice->workspace_id,
            'client_invoice_id' => $invoice->id,
            'recipient_user_id' => $administrator?->id,
            'invoice_revision' => $invoice->document_revision,
            'recipient' => $administrator?->email,
            'subject' => $subject,
            'open_url' => $openUrl,
            'client_name' => $company->name,
            'invoice_number' => (string) $invoice->invoice_number,
            'currency' => (string) $invoice->currency,
            'total_amount' => (int) $invoice->total_amount,
            'status' => $administrator === null ? 'missing_recipient' : 'pending',
            // A missing administrator is checked again later. This is not a
            // delivery attempt, so it does not consume the bounded mail retry
            // count.
            'next_attempt_at' => $administrator === null ? $now->addDay() : $now,
            'error_summary' => $administrator === null
                ? 'No eligible administrator with a valid email address could be resolved.'
                : null,
        ];

        // Preserve the originally issued document even when no recipient is
        // configured yet. It is accounting evidence and is also what makes a
        // later recipient recovery send the original revision rather than a
        // corrected one.
        try {
            $pdf = $this->documents->pdf($invoice, InvoiceLineDetail::OPERATOR);
            $attributes['pdf_filename'] = $this->documents->filename($invoice);
            $attributes['pdf_content_base64'] = base64_encode($pdf);
        } catch (Throwable $exception) {
            $attributes['status'] = 'failed';
            $attributes['failed_at'] = $now;
            $attributes['next_attempt_at'] = $administrator === null ? $now->addDay() : $now;
            $attributes['error_summary'] = 'Invoice PDF generation failed ('.class_basename($exception).').';
        }

        $notification = ClientInvoiceAdministratorNotification::query()->create($attributes);
        $notificationId = $notification->id;
        $workspaceId = $notification->workspace_id;

        if ($notification->status === 'pending') {
            DB::afterCommit(fn () => $this->deliverRegistered($workspaceId, $notificationId));
        }

        return $notification;
    }

    /** Claim and deliver one committed notification. Safe to call repeatedly. */
    public function deliverRegistered(int $workspaceId, int $notificationId): void
    {
        $notification = DB::transaction(function () use ($workspaceId, $notificationId): ?ClientInvoiceAdministratorNotification {
            $candidate = ClientInvoiceAdministratorNotification::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($notificationId)
                ->tap(Locks::forUpdate())
                ->first();

            if (! $candidate instanceof ClientInvoiceAdministratorNotification
                || ! in_array($candidate->status, ['pending', 'failed', 'missing_recipient'], true)
                || $candidate->attempt_count >= self::MAX_ATTEMPTS
                || ($candidate->next_attempt_at !== null && $candidate->next_attempt_at->isFuture())) {
                return null;
            }

            $invoice = ClientInvoice::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($candidate->client_invoice_id)
                ->with('workspace')
                ->first();
            if (! $invoice instanceof ClientInvoice) {
                return null;
            }
            $company = ClientCompany::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($invoice->client_company_id)
                ->first();
            if (! $company instanceof ClientCompany) {
                return null;
            }
            $invoice->setRelation('clientCompany', $company);
            $candidate->setRelation('invoice', $invoice);
            $candidate->setRelation('workspace', $invoice->workspace);

            // Membership and project responsibility can change between issue
            // and a retry. Resolve from tenant-scoped configuration at every
            // claim so a former member is never mailed a financial document.
            $administrator = $this->responsibleAdministrator($invoice);
            if (! $administrator instanceof User) {
                $candidate->forceFill([
                    'recipient_user_id' => null,
                    'recipient' => null,
                    'status' => 'missing_recipient',
                    'next_attempt_at' => $this->clock->now($invoice->workspace)->utc()->addDay(),
                    'error_summary' => 'No eligible administrator with a valid email address could be resolved.',
                ])->save();

                return null;
            }

            $attempt = $candidate->attempt_count + 1;
            $candidate->forceFill([
                'recipient_user_id' => $administrator->id,
                'recipient' => $administrator->email,
                'status' => 'sending',
                'claimed_at' => $this->clock->now($invoice->workspace)->utc(),
                'attempt_count' => $attempt,
                'attempt_history' => $this->withAttemptEvent($candidate, $attempt, 'attempted'),
                'next_attempt_at' => null,
                'failed_at' => null,
                'error_summary' => null,
            ])->save();

            return $candidate;
        });

        if (! $notification instanceof ClientInvoiceAdministratorNotification) {
            return;
        }

        $invoice = $notification->invoice;
        try {
            $pdf = base64_decode((string) $notification->pdf_content_base64, true);
            if ($invoice instanceof ClientInvoice
                && ($pdf === false || $pdf === '')
                && $invoice->document_revision === $notification->invoice_revision) {
                $pdf = $this->documents->pdf($invoice, InvoiceLineDetail::OPERATOR);
                $notification->forceFill([
                    'pdf_filename' => $this->documents->filename($invoice),
                    'pdf_content_base64' => base64_encode($pdf),
                ])->save();
            }
            if (! $invoice instanceof ClientInvoice || $pdf === false || $pdf === '' || $notification->recipient === null) {
                throw new \DomainException('The registered notification is incomplete.');
            }

        } catch (Throwable $exception) {
            $this->recordFailure($notification, $exception);

            return;
        }

        try {
            $sent = Mail::to($notification->recipient)->send(new AdministratorInvoiceIssuedMail(
                $notification->subject,
                $notification->client_name,
                $notification->invoice_number,
                $notification->currency,
                $notification->total_amount,
                $notification->open_url,
                $pdf,
                (string) $notification->pdf_filename,
            ));

        } catch (Throwable $exception) {
            $this->recordFailure($notification, $exception);

            return;
        }

        try {
            $notification->forceFill([
                'status' => 'sent',
                'sent_at' => $this->clock->now($invoice->workspace)->utc(),
                'provider_message_reference' => $sent?->getMessageId(),
                'attempt_history' => $this->withAttemptEvent(
                    $notification,
                    $notification->attempt_count,
                    'accepted',
                ),
            ])->save();
        } catch (Throwable $exception) {
            // The provider already accepted it. Preserve `sending` when the
            // local result could not be saved so a retry cannot duplicate an
            // outcome that must be reconciled.
            Log::critical('An accepted invoice administrator notification could not be persisted.', [
                'notification' => $notification->public_id,
                'exception' => $exception,
            ]);
        }
    }

    /** Retry a bounded page without holding a transaction around the batch. */
    public function dispatchDue(int $limit = 50): int
    {
        $now = $this->clock->now()->utc();
        $workspaceIds = Workspace::query()
            ->whereExists(fn (QueryBuilder $query): QueryBuilder => $query
                ->selectRaw('1')
                ->from('client_invoice_administrator_notifications')
                ->whereColumn('client_invoice_administrator_notifications.workspace_id', 'workspaces.id')
                ->whereIn('status', ['pending', 'failed', 'missing_recipient'])
                ->where('attempt_count', '<', self::MAX_ATTEMPTS)
                ->where(fn (QueryBuilder $due): QueryBuilder => $due
                    ->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', $now)))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id'])
            ->map(fn (Workspace $workspace): int => $workspace->id);

        $inspected = 0;
        foreach ($workspaceIds as $workspaceId) {
            $remaining = $limit - $inspected;
            if ($remaining <= 0) {
                break;
            }
            $ids = ClientInvoiceAdministratorNotification::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('status', ['pending', 'failed', 'missing_recipient'])
                ->where('attempt_count', '<', self::MAX_ATTEMPTS)
                ->where(fn (Builder $query): Builder => $query
                    ->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', $now))
                ->orderBy('id')
                ->limit($remaining)
                ->get(['id'])
                ->map(fn (ClientInvoiceAdministratorNotification $notification): int => $notification->id);

            foreach ($ids as $id) {
                $this->deliverRegistered($workspaceId, $id);
                $inspected++;
            }
        }

        return $inspected;
    }

    private function responsibleAdministrator(ClientInvoice $invoice): ?User
    {
        $projectIds = $invoice->lines()
            ->where('workspace_id', $invoice->workspace_id)
            ->whereNotNull('client_project_id')
            ->get(['client_project_id'])
            ->map(fn (ClientInvoiceLine $line): int => $this->databaseId($line->client_project_id))
            ->unique()
            ->values();

        $agreementProjectId = $invoice->agreement()
            ->where('workspace_id', $invoice->workspace_id)
            ->value('client_project_id');
        if ($agreementProjectId !== null) {
            $projectIds->push($this->databaseId($agreementProjectId));
            $projectIds = $projectIds->unique()->values();
        }

        if ($projectIds->isNotEmpty()) {
            $candidateIds = ClientProjectMembership::query()
                ->where('workspace_id', $invoice->workspace_id)
                ->whereIn('client_project_id', $projectIds->all())
                ->whereIn('role', ['owner', 'manager'])
                ->groupBy('user_id')
                ->havingRaw('count(distinct client_project_id) = ?', [$projectIds->count()])
                ->orderBy('user_id')
                ->pluck('user_id');

            $projectAdministrator = User::query()
                ->whereIn('id', $candidateIds)
                ->whereIn('id', DB::table('workspace_memberships')
                    ->where('workspace_id', $invoice->workspace_id)
                    ->select('user_id'))
                ->orderBy('id')
                ->get()
                ->first(fn (User $user): bool => filter_var($user->email, FILTER_VALIDATE_EMAIL) !== false);

            if ($projectAdministrator instanceof User) {
                return $projectAdministrator;
            }
        }

        return User::query()
            ->whereIn('id', DB::table('workspace_memberships')
                ->where('workspace_id', $invoice->workspace_id)
                ->whereIn('role', ['owner', 'admin'])
                ->select('user_id'))
            ->orderByRaw("case when exists (select 1 from workspace_memberships wm where wm.user_id = users.id and wm.workspace_id = ? and wm.role = 'owner') then 0 else 1 end", [$invoice->workspace_id])
            ->orderBy('id')
            ->get()
            ->first(fn (User $user): bool => filter_var($user->email, FILTER_VALIDATE_EMAIL) !== false);
    }

    private function databaseId(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && $value !== '0') {
            return (int) $value;
        }

        throw new RuntimeException('A persisted invoice relationship contains an invalid database id.');
    }

    private function nextAttemptAt(ClientInvoiceAdministratorNotification $notification): CarbonImmutable
    {
        $minutes = match ($notification->attempt_count) {
            1 => 5,
            2 => 30,
            3 => 120,
            4 => 720,
            default => 1440,
        };

        return $this->clock->now($notification->workspace)->utc()->addMinutes($minutes);
    }

    private function recordFailure(ClientInvoiceAdministratorNotification $notification, Throwable $exception): void
    {
        try {
            $terminal = $notification->attempt_count >= self::MAX_ATTEMPTS;
            $notification->forceFill([
                'status' => 'failed',
                'failed_at' => $this->clock->now($notification->workspace)->utc(),
                'next_attempt_at' => $terminal ? null : $this->nextAttemptAt($notification),
                'error_summary' => 'Administrator notification failed ('.class_basename($exception).').',
                'attempt_history' => $this->withAttemptEvent(
                    $notification,
                    $notification->attempt_count,
                    'failed',
                    class_basename($exception),
                ),
            ])->save();

            Log::error('An invoice administrator notification could not be sent.', [
                'notification' => $notification->public_id,
                'exception' => $exception,
            ]);
        } catch (Throwable $persistenceFailure) {
            Log::critical('An invoice administrator notification failure could not be persisted.', [
                'notification' => $notification->public_id,
                'exception' => $persistenceFailure,
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function withAttemptEvent(
        ClientInvoiceAdministratorNotification $notification,
        int $attempt,
        string $result,
        ?string $failureClass = null,
    ): array {
        $history = $notification->attempt_history ?? [];
        $event = [
            'attempt' => $attempt,
            'result' => $result,
            'at' => $this->clock->now($notification->workspace)->utc()->toISOString(),
        ];
        if ($failureClass !== null) {
            $event['failure_class'] = $failureClass;
        }
        $history[] = $event;

        return $history;
    }
}
