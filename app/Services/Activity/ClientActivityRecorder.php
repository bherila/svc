<?php

namespace App\Services\Activity;

use App\Contracts\WorkspaceOwned;
use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientStripePaymentMethod;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ClientActivityRecorder
{
    public function __construct(private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    /**
     * Append one native activity event and return the existing row on an exact retry.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Workspace $workspace,
        ClientCompany $company,
        string $action,
        Model&WorkspaceOwned $subject,
        array $payload = [],
        ?User $actor = null,
        ?string $occurrence = null,
    ): ClientCompanyActivity {
        $subjectType = $this->subjectType($subject);
        $subjectPublicId = $subject->getAttribute('public_id');
        if ($company->workspace_id !== $workspace->id
            || $subject->workspaceId() !== $workspace->id
            || $this->subjectCompanyId($subject) !== $company->id
            || ! is_string($subjectPublicId)
            || ! Str::isUuid($subjectPublicId)) {
            throw new DomainException('Activity subjects must belong to the selected client company and workspace.');
        }

        $action = trim($action);
        if ($action === '' || strlen($action) > 120) {
            throw new DomainException('Activity actions must contain at most 120 characters.');
        }
        $this->assertSafePayload($payload);
        // The database stores JSON, not PHP's distinction between `1` and
        // `1.0`. Compare the same round-tripped representation that is written
        // so a semantically exact retry cannot conflict only because the JSON
        // decoder normalised a whole-valued float to an integer.
        $payload = json_decode(
            json_encode($payload, JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $actorWasExplicit = $actor !== null;
        $authenticated = request()->user() ?? Auth::user() ?? Auth::guard('api')->user();
        if ($actor === null && $authenticated instanceof AgentPrincipal) {
            $actor = User::query()->find($authenticated->id);
        }
        $actor ??= $authenticated instanceof User ? $authenticated : null;
        $actorBelongs = $actor === null
            || $workspace->memberships()->where('user_id', $actor->id)->exists()
            || $company->portalUsers()->whereKey($actor->id)->exists();
        if (! $actorBelongs && $actorWasExplicit) {
            throw new DomainException('Activity actors must belong to the selected workspace or client company.');
        }
        if (! $actorBelongs) {
            $actor = null;
        }

        $deduplicationKey = hash('sha256', implode('|', [
            $action,
            $subjectType,
            $subjectPublicId,
            $occurrence ?? 'once',
        ]));
        $now = $this->clock->now($workspace);

        $inserted = DB::table('client_company_activity')->insertOrIgnore([
            'public_id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'external_subject_id' => null,
            'subject_public_id' => $subjectPublicId,
            'deduplication_key' => $deduplicationKey,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $activity = ClientCompanyActivity::query()
            ->where('workspace_id', $workspace->id)
            ->where('deduplication_key', $deduplicationKey)
            ->firstOrFail();
        if ($inserted === 0
            && ($activity->actor_user_id !== $actor?->id || ($activity->payload ?? []) !== $payload)) {
            throw new DomainException('An activity occurrence was replayed with different contents.');
        }

        return $activity;
    }

    private function subjectType(Model&WorkspaceOwned $subject): string
    {
        return match (true) {
            $subject instanceof ClientAgreement => 'client_agreement',
            $subject instanceof ClientInvoice => 'client_invoice',
            $subject instanceof ClientInvoicePayment => 'client_invoice_payment',
            $subject instanceof ClientStripePaymentMethod => 'client_stripe_payment_method',
            default => throw new DomainException('This model is not a supported activity subject.'),
        };
    }

    private function subjectCompanyId(Model&WorkspaceOwned $subject): ?int
    {
        if ($subject instanceof ClientInvoicePayment) {
            $companyId = ClientInvoice::query()
                ->where('workspace_id', $subject->workspace_id)
                ->whereKey($subject->client_invoice_id)
                ->value('client_company_id');

            return $companyId === null ? null : (int) $companyId;
        }

        $companyId = $subject->getAttribute('client_company_id');

        return is_int($companyId) ? $companyId : (is_numeric($companyId) ? (int) $companyId : null);
    }

    /** @param array<string, mixed> $payload */
    private function assertSafePayload(array $payload): void
    {
        $inspect = function (array $values) use (&$inspect): void {
            foreach ($values as $key => $value) {
                if (is_string($key) && preg_match('/(?:password|secret|token|credential|authorization|cookie|api[_-]?key|private[_-]?key|raw(?:_|$)|provider_(?:payload|response)|document(?:_|$)|attachment(?:_|$)|file_(?:content|contents)|client_secret)/i', $key) === 1) {
                    throw new DomainException('Sensitive or raw content cannot be stored in client activity.');
                }
                if (is_array($value)) {
                    $inspect($value);
                } elseif (! is_scalar($value) && $value !== null) {
                    throw new DomainException('Client activity payloads may contain only JSON-safe values.');
                }
            }
        };
        $inspect($payload);

        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 10000) {
            throw new DomainException('Client activity payloads may contain at most 10,000 bytes.');
        }
    }
}
