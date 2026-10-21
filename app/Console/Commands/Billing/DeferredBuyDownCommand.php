<?php

namespace App\Console\Commands\Billing;

use App\Console\Commands\Concerns\FindsUserByPublicId;
use App\Console\Commands\MutationCommand;
use App\Services\AgentApi\DeferredBuyDownOperations;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use DomainException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class DeferredBuyDownCommand extends MutationCommand
{
    use FindsUserByPublicId;

    protected $signature = 'svc:billing:deferred-buydown {workspace : Workspace public id} {agreement : Agreement public id}
        {--user= : Billing manager public id} {--hours= : Target hours, in whole minutes} {--amount= : Gross target in minor units}
        {--round-up : Include the next whole entry when the target would otherwise be exceeded}
        {--discount-amount=0 : Explicit discount in minor units} {--discount-reason= : Reason for the discount}
        {--expected-backlog-version= : Version returned by preview; required with --apply}
        {--idempotency-key= : Required with --apply} {--format=text : Output text or json}';

    protected $description = 'Preview deferred backlog settlement, or reserve it on a draft with --apply';

    public function handle(DeferredBuyDownOperations $operations, AgentInvoicePresenter $presenter): int
    {
        $workspace = $this->workspaceByPublicId((string) $this->argument('workspace'));
        if ($workspace === null) {
            $this->error('Workspace not found.');

            return self::INVALID;
        }
        $actor = $this->userByPublicId((string) $this->option('user'));
        if (is_int($actor)) {
            return $actor;
        }
        $body = array_filter(['hours' => $this->option('hours'), 'amount' => $this->option('amount'),
            'round_up' => $this->option('round-up'), 'discount_amount' => $this->option('discount-amount'),
            'discount_reason' => $this->option('discount-reason')], static fn ($value): bool => $value !== null);
        try {
            if ($this->applying()) {
                $write = Validator::make(['key' => $this->option('idempotency-key'), 'version' => $this->option('expected-backlog-version')],
                    ['key' => ['required', 'string', 'max:255'], 'version' => ['required', 'string', 'size:64']])->validate();
                $invoice = $operations->create($actor, $workspace, 'svc-cli', $write['key'], (string) $this->argument('agreement'),
                    $body + ['expected_backlog_version' => $write['version']]);
                $result = ['dry_run' => false, 'data' => $presenter->mutation($workspace, $invoice)];
            } else {
                $result = ['dry_run' => true, 'data' => $operations->preview($actor, $workspace, (string) $this->argument('agreement'), $body)];
            }
            if ($this->option('format') === 'json') {
                $this->json($result);
            } elseif ($result['dry_run']) {
                $this->line($result['data']['hours'].' hours; '.$result['data']['currency'].' '.$result['data']['total_amount'].' minor units.');
                $this->line('Backlog version: '.$result['data']['backlog_version']);
                $this->line('Preview only. Use --apply with this version and an idempotency key to reserve a draft.');
            } else {
                $this->line('Draft created: '.$result['data']['web_url']);
            }

            return self::SUCCESS;
        } catch (DomainException|ValidationException|HttpExceptionInterface $refused) {
            $this->error($refused->getMessage());

            return self::INVALID;
        }
    }
}
