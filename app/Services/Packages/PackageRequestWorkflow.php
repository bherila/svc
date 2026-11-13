<?php

namespace App\Services\Packages;

use App\Actions\CreateClientCompany;
use App\Exceptions\ServicePackageRefused;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageRequest;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\PortalInvitations;
use App\Services\Access\WorkspaceAccess;
use App\Services\Authorization\AgentAccess;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\PortalAccessScope;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\Locks;
use App\Support\Packages\IntakeQuestion;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\PackageRequestStatus;
use App\Support\Packages\PackageStatus;
use App\Support\Packages\PackageTerms;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Sign-up requests from the public page, and the owner or admin's decision.
 *
 * Submitting writes one request row and nothing else (PUB-4). Approving
 * (ADM-2) creates - or reuses - the client company, gives the subscription its
 * own project, writes a draft agreement from the package's currently
 * published version (EDGE-7), records an `offered` enrollment - the offer
 * PackageOffers makes, as for a portal addition - and admits the
 * contact: an address that already signs in here is given company-wide portal
 * access directly (ADM-3); any other is invited through PortalInvitations as
 * the approver.
 *
 * The invitation is the last step of the approval's transaction. If the
 * provider refuses it, the whole approval rolls back and nothing is created.
 * Holding the request's row lock across that one provider call is deliberate:
 * it blocks only a second decision on the same request, and no workspace or
 * access lock is held by this transaction when the call is made -
 * PortalInvitations takes its own afterwards.
 */
final class PackageRequestWorkflow
{
    /** The name an owner or admin may give the new client at approval. */
    public const array COMPANY_NAME_RULES = ['nullable', 'string', 'max:200'];

    /** Open requests one address may have per workspace before more are refused (PUB-5). */
    public const MAX_OPEN_PER_ADDRESS = 3;

    /** What a decline's optional reason may be; the agent contract is generated from it. */
    public const array REASON_RULES = ['nullable', 'string', 'max:1000'];

    public function __construct(
        private readonly AgentAccess $access,
        private readonly CreateClientCompany $companies,
        private readonly PackageOffers $offers,
        private readonly WorkspaceAccess $workspaceAccess,
        private readonly PortalInvitations $invitations,
        private readonly PackageNotifier $notifier,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * Record a visitor's request for a published package (PUB-4).
     *
     * The answers are checked against the questions of the version the form
     * showed (`$shownVersion`, its public id), which is recorded as the
     * version read. A form shown on a version no longer published is refused
     * with a sentence asking the visitor to reload, before any field is
     * checked: a question added since would otherwise fail on a field they
     * cannot see, and one removed would refuse an answer they gave.
     *
     * @param  array<string, mixed>  $input
     * @param  string|null  $shownVersion  null for a form that named no version
     */
    public function submit(Workspace $workspace, ServicePackage $package, array $input, ?User $requester, ?string $fingerprint, ?string $shownVersion = null): ServicePackageRequest
    {
        if ($package->workspace_id !== $workspace->id || $package->packageStatus() !== PackageStatus::Published || $package->published_version_id === null) {
            throw ServicePackageRefused::because(PackageRefusal::PackageNotOffered);
        }
        $version = ServicePackageVersion::query()->where('workspace_id', $workspace->id)->whereKey($package->published_version_id)->firstOrFail();
        if ($shownVersion !== null && $shownVersion !== $version->public_id) {
            throw ServicePackageRefused::because(PackageRefusal::FormChanged);
        }
        $questions = PackageTerms::fromVersion($version)->intakeQuestions;
        if (is_string($input['email'] ?? null)) {
            $input['email'] = trim($input['email']);
        }
        $data = Validator::make($input, self::submissionRules($questions))->validate();
        $email = self::normalizeEmail((string) $data['email']);
        $emailHash = self::emailHash($workspace, $email);

        return DB::transaction(function () use ($workspace, $package, $version, $questions, $data, $email, $emailHash, $requester, $fingerprint): ServicePackageRequest {
            // One submission per address at a time, so the count below and the
            // insert after it cannot interleave with another's (#667).
            $this->lockAddress($workspace, $emailHash);
            $open = ServicePackageRequest::query()
                ->where('workspace_id', $workspace->id)
                ->where('email_hash', $emailHash)
                ->where('status', PackageRequestStatus::New->value)
                ->count();
            if ($open >= self::MAX_OPEN_PER_ADDRESS) {
                throw ServicePackageRefused::because(PackageRefusal::RequestRefused);
            }

            $request = new ServicePackageRequest;
            $request->forceFill([
                'workspace_id' => $workspace->id,
                'service_package_id' => $package->id,
                'service_package_version_id' => $version->id,
                'status' => PackageRequestStatus::New->value,
                'company_name' => trim((string) $data['company_name']),
                'contact_name' => trim((string) $data['contact_name']),
                'email' => $email,
                'email_hash' => $emailHash,
                'phone' => self::nullableText($data['phone'] ?? null),
                'answers' => self::answers($questions, (array) ($data['answers'] ?? [])),
                'requester_user_id' => $requester?->id,
                'client_fingerprint' => $fingerprint,
                'submitted_at' => $this->clock->now(),
            ])->save();

            $this->notifier->requestReceived(
                $workspace,
                $version->name,
                (string) $request->company_name,
                route('packages.requests.index', $workspace, absolute: true),
            );

            return $request;
        });
    }

    /**
     * Approve a request: a client, a project, a draft agreement, an offer, and
     * access for the contact (ADM-1, ADM-2, ADM-3).
     *
     * @param  string|null  $clientPublicId  attach to this existing client instead of creating one
     * @param  string|null  $companyName  the new client's name, when the visitor's needs correcting; ignored when attaching
     * @param  string|null  $expectedOfferVersion  the public id of the version the approver was shown as offered; refused as stale once a newer one is published
     */
    public function approve(User $actor, Workspace $workspace, ServicePackageRequest $request, ?string $clientPublicId = null, ?string $expectedVersion = null, ?string $companyName = null, ?string $expectedOfferVersion = null): ServicePackageEnrollment
    {
        $this->requireManager($actor, $workspace);
        $companyName = self::nullableText($companyName);
        Validator::make(['company_name' => $companyName], ['company_name' => self::COMPANY_NAME_RULES])->validate();
        $existingAccount = null;

        $enrollment = DB::transaction(function () use ($actor, $workspace, $request, $clientPublicId, $expectedVersion, $companyName, $expectedOfferVersion, &$existingAccount): ServicePackageEnrollment {
            // Current reads, not the snapshot an enclosing transaction may
            // already hold (the agent API's receipt reads open one before this
            // runs), in registry order (Codex P1 on #666): the package under a
            // shared lock - which waits out a publish or retire in flight and
            // holds them off until this commits - then the version offered
            // now, which may be newer than the one the visitor read (EDGE-7),
            // then the request.
            $package = ServicePackage::query()->where('workspace_id', $workspace->id)->whereKey($request->service_package_id)
                ->tap(Locks::forShare())->firstOrFail();
            $version = $package->packageStatus() === PackageStatus::Published ? $this->offers->publishedVersion($workspace, $package) : null;
            $locked = $this->locked($workspace, $request);
            $this->assertVersion($locked, $expectedVersion);
            $this->refuseUnlessOpen($locked);

            if ($version === null) {
                throw ServicePackageRefused::because(PackageRefusal::PackageNotOffered);
            }
            // The approver signs off the terms they were shown (EDGE-7).
            abort_unless($expectedOfferVersion === null || $expectedOfferVersion === $version->public_id, 409, 'A newer version of the package was published. Read the request again to see the terms it will be offered on.');

            $email = (string) $locked->email;
            $existingAccount = $this->accountFor($email);
            if ($existingAccount === null && ! PortalInvitations::enabled()) {
                throw ServicePackageRefused::because(PackageRefusal::InvitationsOff);
            }

            $company = $clientPublicId === null
                // The owner or admin may correct the name the visitor typed:
                // once created, it is the workspace's record, not the visitor's.
                ? $this->companies->handle($workspace, $companyName ?? (string) $locked->company_name, $email)
                : ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $clientPublicId)->firstOrFail();
            $enrollment = $this->offers->offer($workspace, $package, $version, $company, $actor, $locked);
            $now = $enrollment->offered_at;

            $locked->forceFill([
                'status' => PackageRequestStatus::Approved->value,
                'client_company_id' => $company->id,
                'decided_by_user_id' => $actor->id,
                'decided_at' => $now,
            ])->save();

            $context = AccessMutationContext::user($actor);
            if ($existingAccount !== null) {
                $this->admitExistingAccount($context, $company, $existingAccount);
            } else {
                // Last, so a provider refusal rolls every write above back.
                $this->invitations->invite($context, $company, $email, PortalAccessScope::Company);
            }

            return $enrollment;
        });

        if ($existingAccount !== null) {
            $company = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($enrollment->client_company_id)->firstOrFail();
            $this->notifier->accessGranted($workspace, $existingAccount->email, $company->name, (string) ServicePackageVersion::query()
                ->where('workspace_id', $workspace->id)->whereKey($enrollment->service_package_version_id)->value('name'), route('portal.show', $company, absolute: true));
        }

        return $enrollment;
    }

    /** Decline a request and tell the visitor (ADM-4). */
    public function decline(User $actor, Workspace $workspace, ServicePackageRequest $request, ?string $reason = null, ?string $expectedVersion = null): ServicePackageRequest
    {
        $this->requireManager($actor, $workspace);
        $reason = self::nullableText($reason);
        Validator::make(['reason' => $reason], ['reason' => self::REASON_RULES])->validate();

        return DB::transaction(function () use ($actor, $workspace, $request, $reason, $expectedVersion): ServicePackageRequest {
            $locked = $this->locked($workspace, $request);
            $this->assertVersion($locked, $expectedVersion);
            $this->refuseUnlessOpen($locked);

            $locked->forceFill([
                'status' => PackageRequestStatus::Declined->value,
                'decided_by_user_id' => $actor->id,
                'decided_at' => $this->clock->now(),
                'decline_reason' => $reason,
            ])->save();

            $packageName = (string) ServicePackageVersion::query()->where('workspace_id', $workspace->id)->whereKey($locked->service_package_version_id)->value('name');
            if ($locked->email !== null) {
                $this->notifier->requestDeclined($workspace, $locked->email, $packageName, $reason);
            }

            return $locked;
        });
    }

    /**
     * Erase what a visitor typed into a decided request (EDGE-10). The row
     * stays as a stub: the package, its dates and the outcome.
     */
    public function erase(User $actor, Workspace $workspace, ServicePackageRequest $request, ?string $expectedVersion = null): ServicePackageRequest
    {
        $this->requireManager($actor, $workspace);

        return DB::transaction(function () use ($workspace, $request, $expectedVersion): ServicePackageRequest {
            $locked = $this->locked($workspace, $request);
            $this->assertVersion($locked, $expectedVersion);
            if (! $locked->requestStatus()->isClosed()) {
                throw ServicePackageRefused::because(PackageRefusal::RequestNotClosed);
            }
            if ($locked->erased_at !== null) {
                return $locked;
            }

            // The address's lock key is a hash of the address: it goes too. It
            // holds nothing, and the next submission from the address makes it
            // again.
            DB::table('service_package_request_addresses')
                ->where('workspace_id', $workspace->id)
                ->where('email_hash', $locked->email_hash)
                ->delete();
            $locked->forceFill([
                'company_name' => null,
                'contact_name' => null,
                'email' => null,
                'email_hash' => str_repeat('0', 64),
                'phone' => null,
                'answers' => null,
                'decline_reason' => null,
                'client_fingerprint' => null,
                'erased_at' => $this->clock->now(),
            ])->save();

            return $locked;
        });
    }

    /**
     * What the public form accepts, for this version's questions.
     *
     * @param  list<IntakeQuestion>  $questions
     * @return array<string, list<mixed>>
     */
    public static function submissionRules(array $questions): array
    {
        $rules = [
            'company_name' => ['required', 'string', 'max:200'],
            'contact_name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:50'],
            'answers' => ['nullable', 'array:'.implode(',', array_map(static fn (IntakeQuestion $question): string => $question->key, $questions))],
        ];
        foreach ($questions as $question) {
            $rules['answers.'.$question->key] = [$question->required ? 'required' : 'nullable', 'string', 'max:'.$question->kind->maxLength()];
        }

        return $rules;
    }

    /**
     * The answers to keep, keyed by question, without blanks.
     *
     * @param  list<IntakeQuestion>  $questions
     * @param  array<mixed>  $answers
     * @return array<string, string>
     */
    private static function answers(array $questions, array $answers): array
    {
        $kept = [];
        foreach ($questions as $question) {
            $answer = self::nullableText($answers[$question->key] ?? null);
            if ($answer !== null) {
                $kept[$question->key] = $answer;
            }
        }

        return $kept;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** A per-workspace hash of an address, so the cap holds after the address is erased from a row. */
    public static function emailHash(Workspace $workspace, string $email): string
    {
        return hash_hmac('sha256', $workspace->id.'|'.self::normalizeEmail($email), (string) config('app.key'));
    }

    /**
     * The local account that already holds this address and signs in through
     * the identity provider, if any. An address held by an account that does
     * not is refused: sign-in could never reach it.
     */
    private function accountFor(string $email): ?User
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [self::normalizeEmail($email)])->first();
        if (! $user instanceof User) {
            return null;
        }
        $provider = (string) config('bherila-auth.oauth_client.provider');
        if ($provider === '' || $user->oauth_provider !== $provider || (string) $user->oauth_subject === '') {
            throw ServicePackageRefused::because(PackageRefusal::AddressHeldElsewhere);
        }

        return $user;
    }

    /**
     * Company-wide portal access for someone who already signs in here. A
     * project-limited contact is widened to the company: the new subscription
     * project is in none of their grants, and the people who accept and pay
     * for a package are company-wide contacts (Codex P2 on #666).
     */
    private function admitExistingAccount(AccessMutationContext $context, ClientCompany $company, User $user): void
    {
        $scope = ClientCompanyMembership::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('user_id', $user->id)
            ->value('access_scope');
        if ($scope === null) {
            $this->workspaceAccess->grantPortal($context, $company, $user, PortalAccessScope::Company);
        } elseif ($scope !== PortalAccessScope::Company->value) {
            $this->workspaceAccess->changePortalScope($context, $company, $user, PortalAccessScope::Company);
        }
    }

    /**
     * Make this address's lock key if it is new, then lock it (#667). An
     * upsert, not an insert that ignores a duplicate: InnoDB locks a duplicate
     * key an upsert meets exclusively, where an ignored insert takes a shared
     * lock that two submissions would each hold while waiting to upgrade it,
     * and one would die as a deadlock (#715). So the second submission waits
     * at the upsert for the first to commit, whether the key is new or not.
     */
    private function lockAddress(Workspace $workspace, string $emailHash): void
    {
        $now = $this->clock->instant();
        DB::table('service_package_request_addresses')->upsert([[
            'workspace_id' => $workspace->id,
            'email_hash' => $emailHash,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['workspace_id', 'email_hash'], ['updated_at']);
        DB::table('service_package_request_addresses')
            ->where('workspace_id', $workspace->id)
            ->where('email_hash', $emailHash)
            ->tap(Locks::forUpdate())
            ->first(['id']);
    }

    private function locked(Workspace $workspace, ServicePackageRequest $request): ServicePackageRequest
    {
        return ServicePackageRequest::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($request->getKey())
            ->tap(Locks::forUpdate())
            ->firstOrFail();
    }

    private function refuseUnlessOpen(ServicePackageRequest $request): void
    {
        if ($request->requestStatus()->isClosed()) {
            throw ServicePackageRefused::because(PackageRefusal::RequestNotOpen);
        }
    }

    private function assertVersion(ServicePackageRequest $request, ?string $expectedVersion): void
    {
        abort_unless($expectedVersion === null || AgentApiVersion::matches($request, $expectedVersion), 409, 'The request changed. Read it again before retrying.');
    }

    private function requireManager(User $actor, Workspace $workspace): void
    {
        // A reader who may not manage the workspace learns nothing about its requests.
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);
    }

    private static function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
