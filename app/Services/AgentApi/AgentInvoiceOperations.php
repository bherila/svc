<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\CadencePeriodGuard;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceCorrectionService;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Billing\InvoiceFromTimeService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\AgentApi\ExplicitConfirmation;
use App\Support\AgentApi\InvoiceSendResult;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use App\Support\Billing\InvoiceEmailDraft;
use App\Support\Billing\InvoiceLineType;
use App\Support\Concurrency\Locks;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Tenant-scoped orchestration; money and lifecycle decisions stay in the shared billing services. */
final class AgentInvoiceOperations
{
    public function __construct(
        private readonly AgentMutationExecutor $mutations,
        private readonly AgentAccess $access,
        private readonly InvoiceCorrectionService $corrections,
        private readonly InvoiceFromTimeService $fromTime,
        private readonly ClientInvoicingService $cadence,
        private readonly CadencePeriodGuard $periods,
        private readonly AgentInvoicePresenter $presenter,
        private readonly CadencePreviewInvoiceChanges $previewChanges,
        private readonly InvoiceEmailService $email,
    ) {}

    /**
     * `invoices.create_draft`: an ad-hoc draft from explicit manual lines
     * and/or approved time, with the invoice screen's fields (#454) - its own
     * number, issue date, service period and client visibility, each
     * defaulting as the screen's form does.
     *
     * @param  array<string, mixed>  $body
     */
    public function createDraft(User $actor, Workspace $workspace, string $client, string $key, array $body): ClientInvoice
    {
        $ids = $this->run('invoices.create_draft', $actor, $workspace, $client, $key, $body, function () use ($workspace, $body): string {
            $rules = $this->draftRules(updating: false);
            // A number is unique within its workspace; asked here so a taken
            // one is a field error rather than a constraint violation.
            $rules['invoice_number'][] = Rule::unique('client_invoices', 'invoice_number')->where('workspace_id', $workspace->id);
            // A period that has both ends runs forwards; one with a single end
            // has nothing to compare.
            if (($body['service_period_start'] ?? null) !== null) {
                $rules['service_period_end'][] = 'after_or_equal:service_period_start';
            }
            $data = $this->validateFields($body, $rules);
            $company = ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $data['company_id'])->firstOrFail();

            return $this->fromTime->create(
                $workspace,
                $company,
                Arr::only($data, ['currency', 'due_date', 'scheduled_issue_date', 'notes', 'invoice_number', 'issue_date', 'service_period_start', 'service_period_end', 'is_visible_to_client']),
                $data['time_entry_ids'] ?? [],
                $data['manual_lines'] ?? [],
            )->public_id;
        });

        return $this->invoice($workspace, $ids[0]);
    }

    /**
     * `invoices.update_draft`: replace an ad-hoc draft's selection and lines.
     *
     * @param  array<string, mixed>  $body
     */
    public function updateDraft(User $actor, Workspace $workspace, string $client, string $key, string $invoice, array $body): ClientInvoice
    {
        $ids = $this->run('invoices.update_draft', $actor, $workspace, $client, $key, ['invoice_id' => $invoice, 'body' => $body], function () use ($workspace, $invoice, $body): string {
            $data = $this->validateFields($body, $this->draftRules(updating: true));

            return $this->fromTime->updateDraft(
                $this->invoice($workspace, $invoice),
                $workspace,
                $data['expected_version'],
                Arr::only($data, ['currency', 'due_date', 'scheduled_issue_date', 'notes']),
                $data['time_entry_ids'],
                $data['manual_lines'],
            )->public_id;
        });

        return $this->invoice($workspace, $ids[0]);
    }

    /**
     * `invoices.send`: email the invoice to explicit recipients, with the
     * invoice screen's compose fields (#454) - a subject, a covering note and
     * a blind copy to the sender's own address.
     *
     * The delivery is registered with this mutation's receipt and sent once it
     * commits: the receipt and the effect have to land together, and an email
     * already gone is not something a rollback can take back.
     *
     * @param  array<string, mixed>  $body
     */
    public function send(User $actor, Workspace $workspace, string $client, string $key, string $invoice, array $body): InvoiceSendResult
    {
        $this->authorize($actor, $workspace);
        $ids = $this->mutations->run($actor, $workspace, $client, 'invoices.send', $key, ['invoice_id' => $invoice, 'body' => $body], function () use ($actor, $workspace, $invoice, $body): array {
            $data = $this->validateFields($body, [
                'expected_version' => ['required', 'string', 'size:64'],
                'confirm' => ['required', new ExplicitConfirmation],
                'recipients' => ['required', 'array', 'min:1', 'max:10'],
                'recipients.*' => ['email', 'max:255'],
                'subject' => ['sometimes', 'nullable', 'string', 'max:'.InvoiceEmailDraft::SUBJECT_MAX_LENGTH],
                'message' => ['sometimes', 'nullable', 'string', 'max:'.InvoiceEmailDraft::MESSAGE_MAX_LENGTH],
                'bcc_self' => ['sometimes', 'boolean:strict'],
            ]);
            $record = $this->invoice($workspace, $invoice, lock: true);
            abort_unless(AgentApiVersion::matches($record, $data['expected_version']), 409, 'The invoice has changed; read it and retry.');
            $delivery = $this->email->sendAfterCommit($record, InvoiceEmailDraft::composed(
                array_values($data['recipients']),
                (string) $actor->email,
                ($data['bcc_self'] ?? false) === true,
                $data['subject'] ?? null,
                $this->email->defaultSubject($record),
                $data['message'] ?? null,
            ), $workspace);

            return [$record->public_id, $delivery->public_id];
        }, fn (array $ids) => $this->authorize($actor, $workspace));

        $record = $this->invoice($workspace, $ids[0]);
        // Old receipts contain only an invoice id. Never guess their delivery
        // from the newest row: a later, unrelated send may already exist.
        $delivery = isset($ids[1]) ? ClientInvoiceEmailDelivery::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_invoice_id', $record->id)
            ->where('public_id', $ids[1])->first() : null;

        return new InvoiceSendResult($record, $delivery);
    }

    /** @param array<string, mixed> $body */
    public function hold(User $actor, Workspace $workspace, string $client, string $key, string $invoice, array $body, bool $hold): ClientInvoice
    {
        $operation = $hold ? 'invoices.hold_delivery' : 'invoices.release_delivery';
        $ids = $this->run($operation, $actor, $workspace, $client, $key, ['invoice_id' => $invoice, 'body' => $body], function () use ($workspace, $invoice, $body, $hold): string {
            $data = $this->validate($body, ['expected_version' => ['required', 'string', 'size:64']] + ($hold ? [] : ['confirm' => ['required', new ExplicitConfirmation]]));
            $record = $this->invoice($workspace, $invoice);
            $check = static fn (ClientInvoice $locked) => abort_unless(AgentApiVersion::matches($locked, $data['expected_version']), 409, 'The invoice has changed; read it and retry.');
            $updated = $hold ? $this->corrections->hold($record, $workspace, $check) : $this->corrections->release($record, $workspace, $check);

            return $updated->public_id;
        });

        return $this->invoice($workspace, $ids[0]);
    }

    /** @param array<string, mixed> $body */
    public function addTime(User $actor, Workspace $workspace, string $client, string $key, string $invoice, array $body): ClientInvoice
    {
        $ids = $this->run('invoices.add_time', $actor, $workspace, $client, $key, ['invoice_id' => $invoice, 'body' => $body], function () use ($workspace, $invoice, $body): string {
            $data = $this->validate($body, ['expected_version' => ['required', 'string', 'size:64'], 'time_entry_ids' => ['required', 'array', 'min:1', 'max:100'], 'time_entry_ids.*' => ['required', 'uuid', 'distinct']]);

            return $this->fromTime->addTime($this->invoice($workspace, $invoice), $workspace, $data['expected_version'], array_values($data['time_entry_ids']))->public_id;
        });

        return $this->invoice($workspace, $ids[0]);
    }

    /** @param array<string, mixed> $body */
    public function generate(User $actor, Workspace $workspace, string $client, string $key, string $agreement, array $body): ClientInvoice
    {
        $ids = $this->run('invoices.generate_period', $actor, $workspace, $client, $key, ['agreement_id' => $agreement, 'body' => $body], function () use ($workspace, $agreement, $body): string {
            $data = $this->validate($body, ['expected_version' => ['required', 'string', 'size:64'], 'period_start' => ['required', 'date_format:Y-m-d'], 'confirm' => ['required', new ExplicitConfirmation]]);
            [$company, $record, $start] = $this->periods->lock($workspace, $agreement, $data['period_start'], $data['expected_version']);

            return $this->cadence->generateCadencePeriod($company, $record, $start)->public_id;
        });

        return $this->invoice($workspace, $ids[0]);
    }

    /**
     * What invoices.generate_period would write for this period, written and
     * then rolled back.
     *
     * Not a second implementation of the cadence engine: the same checks under
     * the same locks, then the same `generateCadencePeriod()` call, read before
     * an unconditional rollback - the rehearsal command's method, narrowed to
     * one period. A prediction computed beside the engine would drift from it;
     * this cannot, because it is the engine. Nothing survives the rollback: no
     * invoice, line, allocation, number or activity, and no receipt or audit,
     * because a read takes no idempotency key.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function preview(User|AgentPrincipal $actor, Workspace $workspace, string $agreement, array $query): array
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);
        $data = $this->validate($query, ['period_start' => ['required', 'date_format:Y-m-d']]);

        DB::beginTransaction();
        try {
            return $this->refusalsAsValidation(function () use ($workspace, $agreement, $data): array {
                [$company, $record, $start] = $this->periods->lock($workspace, $agreement, $data['period_start'], null);
                // Read before generation, which is what a generate call
                // following this preview must present as expected_version.
                $version = AgentApiVersion::for($record);
                $before = $this->previewChanges->before($workspace, $company);
                $draft = $this->cadence->generateCadencePeriod($company, $record, $start);
                $others = $this->previewChanges->after($workspace, $company, $before, (int) $draft->id);

                // Reloaded through the workspace, never by bare primary key.
                $draft = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($draft->id)->firstOrFail();

                return $this->presenter->periodPreview($workspace, $company, $record, $version, $draft, $others);
            });
        } finally {
            // Unconditional, exactly as in the rehearsal: a preview that
            // returned, refused or failed has written nothing.
            DB::rollBack();
        }
    }

    /** @param array<string, mixed> $payload
     * @param Closure(): string $work
     * @return list<string> */
    private function run(string $operation, User $actor, Workspace $workspace, string $client, string $key, array $payload, Closure $work): array
    {
        $this->authorize($actor, $workspace);

        return $this->mutations->run($actor, $workspace, $client, $operation, $key, $payload, fn (): array => $this->refusalsAsValidation(static fn (): array => [$work()]), fn (array $ids) => $this->authorize($actor, $workspace));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function refusalsAsValidation(Closure $work): mixed
    {
        try {
            return $work();
        } catch (RuntimeException $refused) {
            // The legacy cadence engine uses plain RuntimeException for
            // its domain refusals. Database/adapter exception subclasses
            // must retain their real failure semantics.
            if ($refused::class !== RuntimeException::class) {
                throw $refused;
            }
            throw ValidationException::withMessages(['billing' => $refused->getMessage()]);
        }
    }

    /** @param array<string, mixed> $body
     * @param array<string, list<mixed>> $rules
     * @return array<string, mixed> */
    private function validate(array $body, array $rules): array
    {
        $fields = array_filter(array_keys($rules), fn (string $field): bool => ! str_contains($field, '.'));
        $closed = ['body' => ['required', 'array:'.implode(',', $fields)]];
        foreach ($rules as $field => $rule) {
            $closed['body.'.$field] = $rule;
        }

        return Validator::make(['body' => $body], $closed)->validate()['body'];
    }

    /**
     * Validate a closed body with its errors keyed by field, as these
     * operations have always reported them: a field the operation does not
     * take is refused by name rather than ignored, and a rule may name a
     * sibling field (`required_with`, `after_or_equal`) as written.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, mixed>
     */
    private function validateFields(array $body, array $rules): array
    {
        $fields = array_filter(array_keys($rules), static fn (string $field): bool => ! str_contains($field, '.'));
        $unknown = array_diff(array_map('strval', array_keys($body)), $fields);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys(array_values($unknown), 'This field is not accepted by this operation.'));
        }

        return Validator::make($body, $rules)->validate();
    }

    /**
     * The draft body, for creation and for replacement. Creation also takes
     * the screen form's own fields; a replacement keeps the ones it was
     * created with.
     *
     * @return array<string, list<mixed>>
     */
    private function draftRules(bool $updating): array
    {
        $rules = [
            'currency' => ['nullable', 'string', 'size:3'],
            'due_date' => ['nullable', 'date'],
            'scheduled_issue_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'time_entry_ids' => [$updating ? 'present' : 'sometimes', 'array', 'max:100'],
            'time_entry_ids.*' => ['uuid', 'distinct'],
            'manual_lines' => [$updating ? 'present' : 'sometimes', 'array', 'max:100'],
            'manual_lines.*' => ['array:project_id,type,description,quantity,unit_amount,tax_amount'],
            'manual_lines.*.project_id' => ['nullable', 'uuid'],
            'manual_lines.*.type' => ['required', 'string', 'max:40', Rule::notIn(InvoiceLineType::systemOnlyValues())],
            'manual_lines.*.description' => ['required', 'string', 'max:10000'],
            'manual_lines.*.quantity' => ['required'],
            'manual_lines.*.unit_amount' => ['required', 'integer', 'min:0'],
            'manual_lines.*.tax_amount' => ['nullable', 'integer', 'min:0'],
        ];
        if ($updating) {
            return $rules + ['expected_version' => ['required', 'string', 'size:64']];
        }

        return $rules + [
            'company_id' => ['required', 'uuid'],
            // The screen form's fields. Each omitted one defaults as it does
            // there: the workspace's next number, no issue date (issue() sets
            // today), no service period, and hidden from the client until it
            // is issued.
            'invoice_number' => ['sometimes', 'nullable', 'string', 'max:80'],
            'issue_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // Either end may be given alone, as on the screen: an ad-hoc
            // invoice may bill from a day, or up to one.
            'service_period_start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'service_period_end' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'is_visible_to_client' => ['sometimes', 'boolean:strict'],
        ];
    }

    private function authorize(User $actor, Workspace $workspace): void
    {
        abort_unless(AgentWriteCutover::invoices(), 404);
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403);
    }

    private function invoice(Workspace $workspace, string $id, bool $lock = false): ClientInvoice
    {
        $query = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->whereHas('clientCompany', fn ($companies) => $companies->where('workspace_id', $workspace->id))->with(['clientCompany' => fn ($companies) => $companies->where('workspace_id', $workspace->id)]);
        if ($lock) {
            $query->tap(Locks::forUpdate());
        }

        return $query->firstOrFail();
    }
}
