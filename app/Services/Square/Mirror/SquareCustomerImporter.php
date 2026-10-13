<?php

namespace App\Services\Square\Mirror;

use App\Actions\CreateClientCompany;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\CompanySquareCustomer;
use App\Models\SquareCustomerSnapshot;
use App\Models\SquareInvoiceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\Authorization\AgentAccess;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquareLinkRepository;
use App\Services\Square\SquareRequestFailed;
use App\Support\AgentApi\ExplicitConfirmation;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Brings a Square-only customer into svc, one customer at a time and only on
 * an explicit request (#438). There is no bulk import.
 *
 * It creates the client company, confirms the Square customer as that
 * company's, and - when a series is named - a draft agreement mirroring the
 * series (its cadence, amount, currency and first due date) marked as billed
 * in Square, so svc never collects its invoices. The agreement stays a draft
 * for a person to review and activate. Provenance is the company's activity:
 * which Square customer and series it came from, and who imported it.
 *
 * All of it is one transaction under the agent mutation receipt, so a retry
 * returns the same company and nothing is half-imported. It reads only svc's
 * mirror; nothing is sent to Square. No row is locked: every row it writes is
 * new, and the unique Square-customer and series indexes answer a concurrent
 * import of the same customer or series with the same 409 a sequential one
 * gets.
 */
final class SquareCustomerImporter
{
    private const array CADENCES = ['monthly', 'quarterly', 'semi_annual', 'annual'];

    public function __construct(
        private readonly AgentMutationExecutor $mutations,
        private readonly AgentAccess $access,
        private readonly CreateClientCompany $companies,
        private readonly AgreementWorkflow $agreements,
        private readonly SquareLinkRepository $links,
        private readonly SquareMirrorReadService $mirror,
        private readonly ClientActivityRecorder $activities,
        private readonly WorkspaceClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: ClientCompany, 1: ClientAgreement|null}
     */
    public function import(User $user, Workspace $workspace, string $clientId, string $key, string $customerId, array $body): array
    {
        $this->admit($user, $workspace);
        $ids = $this->mutations->run(
            $user,
            $workspace,
            $clientId,
            'square.import_customer',
            $key,
            ['customer_id' => $customerId, 'body' => $body],
            function () use ($user, $workspace, $customerId, $body): array {
                $data = Validator::make($body, [
                    'confirm' => ['required', new ExplicitConfirmation],
                    'company_name' => ['sometimes', 'nullable', 'string', 'min:1', 'max:200'],
                    'billing_email' => ['sometimes', 'nullable', 'email', 'max:254'],
                    'series_key' => ['sometimes', 'nullable', 'string', 'regex:'.SquareMirrorReadService::SERIES_KEY],
                    'agreement_title' => ['sometimes', 'nullable', 'string', 'min:1', 'max:200'],
                ])->validate();

                return $this->importNow($user, $workspace, $customerId, $data);
            },
            fn (array $ids) => $this->admit($user, $workspace),
        );
        $company = ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $ids[0] ?? '')->firstOrFail();
        $agreement = isset($ids[1]) ? ClientAgreement::query()->where('workspace_id', $workspace->id)->where('public_id', $ids[1])->firstOrFail() : null;

        return [$company, $agreement];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string> the company's public id, then the agreement's when one was made
     */
    private function importNow(User $user, Workspace $workspace, string $customerId, array $data): array
    {
        abort_if(SquareCustomerSnapshot::onlyTrashed()->where('workspace_id', $workspace->id)->where('square_customer_id', $customerId)->exists(), 404, 'The Square customer is no longer present. Sync, then try again.');
        $customer = SquareCustomerSnapshot::query()->where('workspace_id', $workspace->id)->where('square_customer_id', $customerId)->first();
        $latest = SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->where('square_customer_id', $customerId)->orderByDesc('id')->first();
        abort_if($customer === null && $latest === null, 404, 'The Square mirror has no customer with that id. Sync, then try again.');
        $mapped = $this->links->activeCustomerByCustomerId($workspace->id, $customerId);
        if ($mapped !== null) {
            $name = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($mapped->client_company_id)->value('name');
            abort(409, sprintf('That Square customer is already the confirmed customer of %s.', is_string($name) ? $name : 'another client'));
        }
        $name = $data['company_name'] ?? $customer?->displayName() ?? $latest?->recipient_name;
        if (! is_string($name) || trim($name) === '') {
            throw new DomainException('Square has no name for this customer. Pass company_name.');
        }
        $email = array_key_exists('billing_email', $data) ? $data['billing_email'] : self::email($customer?->email_address);
        $series = isset($data['series_key']) ? $this->series($workspace, $customerId, (string) $data['series_key']) : null;

        try {
            return DB::transaction(function () use ($user, $workspace, $customerId, $data, $name, $email, $series): array {
                $company = $this->companies->handle($workspace, mb_substr(trim($name), 0, 200), is_string($email) ? $email : null);
                $now = $this->clock->now($workspace)->utc();
                CompanySquareCustomer::query()->create([
                    'workspace_id' => $workspace->id,
                    'client_company_id' => $company->id,
                    'square_customer_id' => $customerId,
                    'active_company_slot' => $company->id,
                    'active_customer_slot' => $customerId,
                    'confirmed_by_user_id' => $user->id,
                    'confirmed_at' => $now,
                ]);
                $agreement = null;
                if ($series !== null) {
                    $agreement = $this->agreements->create($workspace, $company, null, null, [
                        'title' => $data['agreement_title'] ?? sprintf('Square series %s', $series['series_key']),
                        'starts_on' => $series['first_due_on'],
                        'billing_cadence' => $series['cadence'],
                        'currency' => $series['currency'],
                        'retainer_amount' => $series['amount'],
                    ]);
                    // Billed in Square: svc issues and records, Square collects.
                    $agreement->forceFill(['square_series_key' => $series['series_key']])->save();
                }
                $this->activities->record($workspace, $company, 'company.imported_from_square', $company, [
                    'square_customer_id' => $customerId,
                    'series_key' => $series['series_key'] ?? null,
                    'agreement_id' => $agreement?->public_id,
                ], $user, (string) Str::uuid());

                return array_values(array_filter([$company->public_id, $agreement?->public_id]));
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'That Square customer or series was just imported or mapped elsewhere. Read the clients and retry.');
        }
    }

    /**
     * The series to mirror: this customer's, readable as an agreement, and
     * not already marking one.
     *
     * @return array{series_key: string, cadence: string, currency: string, amount: int, first_due_on: string}
     */
    private function series(Workspace $workspace, string $customerId, string $seriesKey): array
    {
        $summary = $this->mirror->summaries($workspace, [$seriesKey])[0];
        if ($summary['invoice_count'] === 0) {
            abort(404, sprintf('The Square mirror has no series %s.', $seriesKey));
        }
        if ($summary['customer_id'] !== $customerId || $summary['customer_count'] !== 1) {
            throw new DomainException(sprintf('Square series %s does not belong to this customer alone.', $seriesKey));
        }
        if (! in_array($summary['cadence'], self::CADENCES, true) || ! is_string($summary['currency']) || ! is_int($summary['amount']) || ! is_string($summary['first_due_on'])) {
            throw new DomainException(sprintf('Square series %s has no monthly, quarterly, semi-annual or annual cadence, one currency and an amount to mirror. Import the customer without it and set the agreement up by hand.', $seriesKey));
        }
        $holder = $this->links->agreementForSeries($workspace->id, $seriesKey);
        if ($holder !== null) {
            abort(409, sprintf('Square series %s already marks agreement "%s".', $seriesKey, (string) $holder->title));
        }

        return ['series_key' => $seriesKey, 'cadence' => $summary['cadence'], 'currency' => $summary['currency'], 'amount' => $summary['amount'], 'first_due_on' => $summary['first_due_on']];
    }

    private static function email(?string $email): ?string
    {
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 254 ? $email : null;
    }

    private function admit(User $user, Workspace $workspace): void
    {
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403, 'Importing a Square customer is for workspace managers only.');
        if (! SquareConfiguration::fromConfig()->servesWorkspace($workspace)) {
            throw SquareRequestFailed::notConnected();
        }
    }
}
